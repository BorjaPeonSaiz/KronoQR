<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Product\Domain\ValueObject\ErrorEventSummary;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorWriteOutcome;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * La tabla `error_events` (**RF-PD-15**).
 *
 * ## DOS CONEXIONES, y es la decision central de esta clase
 *
 * - **Escribir usa la conexion `error_events`**, que es una sesion distinta de
 *   PostgreSQL contra la misma base y con el mismo rol (ver el comentario de
 *   `config/database.php`). Sin ella, el `INSERT` del error entraria dentro de
 *   la transaccion que acaba de fallar y **se revertiria con ella** —el unico
 *   rastro del error desapareceria justo por ser un error—, o chocaria con un
 *   `25P02` si PostgreSQL ya la aborto, convirtiendo un error en dos (regla dura
 *   19).
 * - **Leer, resolver y purgar usan la conexion normal.** Los tres ocurren desde
 *   una pantalla o desde un comando, con la transaccion en su sitio, y tienen
 *   que ver lo que ve el resto de la aplicacion.
 *
 * ## La agrupacion la da el UNIQUE, no el codigo
 *
 * `INSERT … ON CONFLICT (fingerprint) DO UPDATE`: **una sentencia**, sin
 * `SELECT` previo. Es la misma garantia que la idempotencia del fichaje (regla
 * dura 8) y por el mismo motivo: cientos de errores simultaneos en un cambio de
 * turno es el caso normal, y cualquier comprobacion previa dejaria pasar la
 * carrera. La prueba de integracion lanza cincuenta procesos con la misma huella
 * y exige **una fila con `occurrences = 50`**.
 *
 * ## Que se actualiza al repetirse, y que no
 *
 * Sube `occurrences`; se refrescan `app_version`, `trace_id`, `device_id` y
 * `employee_uuid` —son lo que sirve para ir a buscar, y lo util es lo mas
 * reciente—. **No** se tocan `message`, `exception_class`, `file` ni `line`: son
 * los de la primera aparicion, para que el grupo no cuente una historia distinta
 * cada vez que alguien lo mira.
 *
 * Los dos instantes se mueven con `GREATEST` y `LEAST` y **nunca con el valor
 * que llega tal cual**: un reporte de la cola offline de una tablet puede
 * describir algo de hace tres dias y llegar hoy. Con una asignacion directa,
 * `last_seen_at` retrocederia y el grupo, que esta vivo, envejeceria hacia la
 * purga; y `first_seen_at` avanzaria, borrando desde cuando pasa. Con estas dos
 * funciones, `first_seen_at <= last_seen_at` es cierto por construccion.
 *
 * ## La reapertura es CONDICIONAL
 *
 * Un grupo resuelto que vuelve a ocurrir se reabre —`resolved_at` y
 * `resolved_by_user_id` a nulo—, porque que un fallo dado por arreglado
 * reaparezca es exactamente lo que IT tiene que ver. Pero **solo si la
 * ocurrencia es posterior a `resolved_at`**: un reporte atrasado describe algo
 * que paso antes del arreglo, y reabrir con el le diria a quien lo arreglo que
 * no funciono cuando lo que ha llegado es historia.
 *
 * ## La escritura NO lanza; la lectura SI
 *
 * Contrato de {@see ErrorEventSink}: un
 * error al guardar el error no puede convertirse en un segundo error. Las
 * lecturas, en cambio, fallan en voz alta: un historico que se muestra vacio
 * porque la consulta se rompio en silencio es peor que una pantalla de error,
 * porque el IT del cliente concluiria que no esta pasando nada.
 */
final readonly class DatabaseErrorEventRepository implements ErrorEventRepository
{
    /**
     * Las columnas del listado, con el autor de la resolucion ya resuelto a
     * `uuid` y nombre.
     *
     * `LEFT JOIN` y no `INNER`: un grupo abierto no tiene autor, y la clave
     * ajena es `nullOnDelete`, asi que una cuenta borrada tampoco puede hacer
     * desaparecer la fila del historico.
     */
    private const string SELECT = <<<'SQL'
        SELECT e.id,
               e.fingerprint,
               e.level,
               e.source,
               e.module,
               e.code,
               e.message,
               e.exception_class,
               e.file,
               e.line,
               e.context::text AS context,
               e.trace_id,
               e.device_id::text AS device_id,
               e.employee_uuid::text AS employee_uuid,
               e.app_version,
               e.occurrences,
               e.first_seen_at,
               e.last_seen_at,
               e.resolved_at,
               u.uuid::text AS resolved_by_uuid,
               u.name AS resolved_by_name
          FROM error_events e
          LEFT JOIN users u ON u.id = e.resolved_by_user_id
        SQL;

    public function __construct(
        private ConnectionInterface $reads,
        private ConnectionInterface $writes,
    ) {}

    public function upsert(
        ErrorReport $report,
        ErrorFingerprint $fingerprint,
        string $message,
        array $context,
        DateTimeImmutable $seenAt,
        DateTimeImmutable $recordedAt,
    ): ErrorWriteOutcome {
        $occurredAt = self::timestamp($seenAt);
        $now = self::timestamp($recordedAt);

        try {
            /*
             * LA CTE `previo` LEE EL ESTADO DE ANTES, y es lo unico que permite
             * distinguir «grupo reabierto» de «grupo que ya estaba abierto».
             *
             * `RETURNING` de un `ON CONFLICT DO UPDATE` solo ve la fila NUEVA y
             * `excluded`, nunca la anterior; y `xmax = 0` solo distingue
             * insercion de actualizacion. Sin este `SELECT`, la metrica de
             * grupos abiertos —la que sostiene la alerta— no se podria calcular.
             *
             * La CTE se evalua sobre la instantanea del inicio de la sentencia,
             * asi que ve el estado previo aunque el `INSERT` actualice despues.
             * No toma bloqueo: en la carrera improbable de dos reaperturas
             * simultaneas, el peor desenlace es una apertura contada dos veces
             * en una metrica. La FILA nunca se ve afectada: de eso se encarga el
             * `ON CONFLICT`, que sigue siendo una sola sentencia atomica.
             */
            /** @var list<object{inserted: bool|string|int, reopened: bool|string|int|null}> $result */
            $result = $this->writes->select(
                <<<'SQL'
                    WITH previo AS (
                        SELECT fingerprint, resolved_at FROM error_events WHERE fingerprint = ?
                    ), escrito AS (
                        INSERT INTO error_events (
                            fingerprint, level, source, module, code, message,
                            exception_class, file, line, context, trace_id,
                            device_id, employee_uuid, app_version, occurrences,
                            first_seen_at, last_seen_at, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?::jsonb, ?,
                            ?::uuid, ?::uuid, ?, 1,
                            ?, ?, ?, ?
                        )
                        ON CONFLICT (fingerprint) DO UPDATE SET
                            occurrences = error_events.occurrences + 1,
                            last_seen_at = GREATEST(error_events.last_seen_at, EXCLUDED.last_seen_at),
                            first_seen_at = LEAST(error_events.first_seen_at, EXCLUDED.first_seen_at),
                            app_version = EXCLUDED.app_version,
                            trace_id = EXCLUDED.trace_id,
                            device_id = EXCLUDED.device_id,
                            employee_uuid = EXCLUDED.employee_uuid,
                            resolved_at = CASE
                                WHEN error_events.resolved_at IS NOT NULL
                                     AND EXCLUDED.last_seen_at > error_events.resolved_at
                                THEN NULL
                                ELSE error_events.resolved_at
                            END,
                            resolved_by_user_id = CASE
                                WHEN error_events.resolved_at IS NOT NULL
                                     AND EXCLUDED.last_seen_at > error_events.resolved_at
                                THEN NULL
                                ELSE error_events.resolved_by_user_id
                            END,
                            updated_at = EXCLUDED.updated_at
                        RETURNING fingerprint, (xmax = 0) AS inserted, resolved_at
                    )
                    SELECT e.inserted,
                           (NOT e.inserted AND p.resolved_at IS NOT NULL AND e.resolved_at IS NULL) AS reopened
                      FROM escrito e
                      LEFT JOIN previo p ON p.fingerprint = e.fingerprint
                    SQL,
                [
                    $fingerprint->value,
                    $fingerprint->value,
                    $report->level->value,
                    $report->source->value,
                    $report->module,
                    $report->code,
                    $message,
                    $report->exceptionClass,
                    $report->file,
                    $report->line,
                    json_encode($context === [] ? new \stdClass : $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $report->traceId,
                    $report->deviceId,
                    $report->employeeUuid,
                    $report->appVersion,
                    $occurredAt,
                    $occurredAt,
                    $now,
                    $now,
                ],
            );

            if ($result === []) {
                // Inalcanzable: `ON CONFLICT DO UPDATE` siempre devuelve fila.
                // Se contempla porque el tipo lo admite y porque decir «no se
                // pudo» es preferible a afirmar que se abrio un grupo.
                return ErrorWriteOutcome::Failed;
            }

            if (self::flag($result[0]->inserted) || self::flag($result[0]->reopened)) {
                return ErrorWriteOutcome::Opened;
            }

            return ErrorWriteOutcome::Recurred;
        } catch (Throwable) {
            /*
             * Silencio deliberado y acotado a este metodo. Quien llama
             * -{@see \App\Modules\Product\Application\UseCase\RecordErrorEvent}-
             * deja constancia en el log tecnico y devuelve `false`; aqui no se
             * registra nada para no duplicar la linea. La tabla puede no existir
             * todavia -una instalacion a medio actualizar- y eso no puede
             * tumbar ni una peticion ni el latido de un quiosco (regla dura 19).
             */
            return ErrorWriteOutcome::Failed;
        }
    }

    public function countOpenGroups(ErrorSource $source): int
    {
        /** @var object{total: int|string}|null $row */
        $row = $this->reads->selectOne(
            'SELECT count(*) AS total FROM error_events WHERE source = ? AND resolved_at IS NULL',
            [$source->value],
        );

        return (int) ($row->total ?? 0);
    }

    public function exists(ErrorFingerprint $fingerprint): bool
    {
        return $this->reads->selectOne(
            'SELECT 1 AS found FROM error_events WHERE fingerprint = ?',
            [$fingerprint->value],
        ) !== null;
    }

    /**
     * `true`, `'t'`, `1`… PostgreSQL devuelve el booleano de una forma u otra
     * segun como este compilado PDO, y de esto depende una metrica.
     */
    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    public function page(ErrorEventQuery $query): ErrorEventPage
    {
        [$where, $bindings] = self::filters($query);

        /** @var object{total: int|string}|null $count */
        $count = $this->reads->selectOne(
            'SELECT count(*) AS total FROM error_events e'.$where,
            $bindings,
        );

        $total = (int) ($count->total ?? 0);
        $perPage = max(1, $query->perPage);
        $offset = (max(1, $query->page) - 1) * $perPage;

        /** @var list<object> $rows */
        $rows = $this->reads->select(
            self::SELECT.$where.' ORDER BY e.last_seen_at DESC, e.id DESC LIMIT ? OFFSET ?',
            [...$bindings, $perPage, $offset],
        );

        [$openErrors, $openCritical] = $this->openCounts();

        return new ErrorEventPage(
            rows: array_map(self::hydrate(...), $rows),
            total: $total,
            page: max(1, $query->page),
            perPage: $perPage,
            openErrors: $openErrors,
            openCritical: $openCritical,
        );
    }

    public function resolve(int $id, int $userId, DateTimeImmutable $at): ?ErrorEvent
    {
        /*
         * IDEMPOTENTE POR EL `WHERE`, no por una comprobacion previa.
         *
         * `AND resolved_at IS NULL` es lo que hace que la segunda pulsacion no
         * reescriba ni el autor ni el instante: no actualiza ninguna fila. Con
         * un `SELECT` antes, dos pestanas abiertas producirian dos autores
         * distintos para la misma resolucion, y el segundo ganaria.
         */
        $this->reads->update(
            'UPDATE error_events SET resolved_at = ?, resolved_by_user_id = ?, updated_at = ? '
            .'WHERE id = ? AND resolved_at IS NULL',
            [self::timestamp($at), $userId, self::timestamp($at), $id],
        );

        /** @var list<object> $rows */
        $rows = $this->reads->select(self::SELECT.' WHERE e.id = ?', [$id]);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function pruneOlderThan(DateTimeImmutable $cutoff, int $batchSize): int
    {
        /*
         * POR LOTES, con el mismo criterio que `DatabaseErrorHistoryArchive` del
         * ciclo de retencion: cada `DELETE` acotado por un `LIMIT` sobre el
         * indice de `last_seen_at`, en bucle hasta que no queda nada.
         *
         * No es microoptimizacion. Un `DELETE` sin `LIMIT` sobre noventa dias de
         * acumulacion es una sentencia larga que mantiene bloqueos de fila sobre
         * la misma tabla en la que se esta escribiendo **cada error que ocurra
         * mientras corre** — y esto se ejecuta a las 03:35, que es justo cuando
         * corren las tareas nocturnas que mas errores producen. El bucle acota
         * cada sentencia y suelta entre una y otra.
         *
         * El predicado es EXACTAMENTE el de la simulacion (`<`, por
         * `last_seen_at`): un ensayo que dijera una cifra y una ejecucion que se
         * llevara otra seria peor que no tener ensayo.
         *
         * El `SELECT id … LIMIT` va dentro del `DELETE` y no antes: con dos
         * sentencias, entre una y otra podria llegar una ocurrencia que reviviera
         * uno de esos grupos y se borraria de todos modos.
         */
        $size = max(1, $batchSize);
        $timestamp = self::timestamp($cutoff);
        $removed = 0;

        do {
            $batch = $this->reads->delete(
                <<<'SQL'
                    DELETE FROM error_events
                     WHERE id IN (
                        SELECT id FROM error_events WHERE last_seen_at < ? ORDER BY id LIMIT ?
                     )
                    SQL,
                [$timestamp, $size],
            );

            $removed += $batch;
        } while ($batch === $size);

        return $removed;
    }

    public function summary(DateTimeImmutable $since): ErrorEventSummary
    {
        /** @var list<object{source: string, level: string, open: int|string, resolved: int|string}> $rows */
        $rows = $this->reads->select(
            <<<'SQL'
                SELECT source,
                       level,
                       count(*) FILTER (WHERE resolved_at IS NULL)     AS open,
                       count(*) FILTER (WHERE resolved_at IS NOT NULL) AS resolved
                  FROM error_events
                 WHERE last_seen_at >= ?
                 GROUP BY source, level
                SQL,
            [self::timestamp($since)],
        );

        // Todas las claves siempre, tambien las que no tienen ni un error: un
        // paquete al que le falta `scheduler` no dice «no hubo errores del
        // planificador», dice que quien lo lee tiene que acordarse de esa fila.
        $perSource = array_fill_keys(ErrorSource::names(), ['open' => 0, 'resolved' => 0]);
        $perLevel = array_fill_keys(ErrorLevel::names(), ['open' => 0, 'resolved' => 0]);

        $totalOpen = 0;
        $totalResolved = 0;

        foreach ($rows as $row) {
            $open = (int) $row->open;
            $resolved = (int) $row->resolved;

            if (isset($perSource[$row->source])) {
                $perSource[$row->source]['open'] += $open;
                $perSource[$row->source]['resolved'] += $resolved;
            }

            if (isset($perLevel[$row->level])) {
                $perLevel[$row->level]['open'] += $open;
                $perLevel[$row->level]['resolved'] += $resolved;
            }

            $totalOpen += $open;
            $totalResolved += $resolved;
        }

        return new ErrorEventSummary($perSource, $perLevel, $totalOpen, $totalResolved);
    }

    /**
     * Los dos recuentos de la cabecera: abiertos por nivel **en toda la
     * instalacion**, sin ninguno de los filtros de la consulta.
     *
     * Ver {@see ErrorEventPage}: si dependieran del filtro, alguien mirando
     * `source=console` leeria «0 criticos» con la cola cayendose al lado.
     *
     * @return array{0: int, 1: int}
     */
    private function openCounts(): array
    {
        /** @var list<object{level: string, total: int|string}> $rows */
        $rows = $this->reads->select(
            'SELECT level, count(*) AS total FROM error_events WHERE resolved_at IS NULL GROUP BY level',
        );

        $counts = [ErrorLevel::Error->value => 0, ErrorLevel::Critical->value => 0];

        foreach ($rows as $row) {
            if (isset($counts[$row->level])) {
                $counts[$row->level] = (int) $row->total;
            }
        }

        return [$counts[ErrorLevel::Error->value], $counts[ErrorLevel::Critical->value]];
    }

    /**
     * El `WHERE` de la consulta y sus parametros, compartidos por el recuento y
     * por la pagina.
     *
     * **Los dos usan exactamente el mismo predicado**, y por eso se compone una
     * sola vez: un `total` calculado con filtros distintos de los de las filas
     * daria una paginacion que no termina nunca o que salta paginas.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private static function filters(ErrorEventQuery $query): array
    {
        $clauses = [];
        $bindings = [];

        if ($query->source instanceof ErrorSource) {
            $clauses[] = 'e.source = ?';
            $bindings[] = $query->source->value;
        }

        if ($query->level instanceof ErrorLevel) {
            $clauses[] = 'e.level = ?';
            $bindings[] = $query->level->value;
        }

        $clauses[] = match ($query->status) {
            ErrorEventStatusFilter::Open => 'e.resolved_at IS NULL',
            ErrorEventStatusFilter::Resolved => 'e.resolved_at IS NOT NULL',
            ErrorEventStatusFilter::All => '1 = 1',
        };

        // Por `last_seen_at` y los dos inclusivos: lo que interesa de un grupo
        // es cuando paso la ultima vez. Uno que empezo hace un ano y sigue
        // ocurriendo hoy tiene que salir en «lo de esta semana».
        if ($query->from instanceof DateTimeImmutable) {
            $clauses[] = 'e.last_seen_at >= ?';
            $bindings[] = self::timestamp($query->from);
        }

        if ($query->to instanceof DateTimeImmutable) {
            $clauses[] = 'e.last_seen_at <= ?';
            $bindings[] = self::timestamp($query->to);
        }

        return [' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    private static function hydrate(object $raw): ErrorEvent
    {
        $row = Row::of($raw);

        /** @var array<string, scalar> $context */
        $context = $row->json('context') ?? [];

        return new ErrorEvent(
            id: $row->int('id'),
            fingerprint: $row->string('fingerprint'),
            level: ErrorLevel::from($row->string('level')),
            source: ErrorSource::from($row->string('source')),
            module: $row->nullableString('module'),
            code: $row->nullableString('code'),
            message: $row->string('message'),
            exceptionClass: $row->nullableString('exception_class'),
            file: $row->nullableString('file'),
            line: $row->nullableInt('line'),
            context: $context,
            traceId: $row->nullableString('trace_id'),
            deviceId: $row->nullableString('device_id'),
            employeeUuid: $row->nullableString('employee_uuid'),
            appVersion: $row->string('app_version'),
            occurrences: $row->int('occurrences'),
            firstSeenAt: $row->instant('first_seen_at'),
            lastSeenAt: $row->instant('last_seen_at'),
            resolvedAt: $row->nullableInstant('resolved_at'),
            resolvedByUuid: $row->nullableString('resolved_by_uuid'),
            resolvedByName: $row->nullableString('resolved_by_name'),
        );
    }

    /** ISO-8601 en UTC con microsegundos, que es como se almacena todo (regla dura 3). */
    private static function timestamp(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.uP');
    }
}
