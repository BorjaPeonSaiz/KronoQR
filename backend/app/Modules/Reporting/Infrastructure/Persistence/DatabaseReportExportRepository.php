<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Domain\Exception\ReportExportAlreadyInProgress;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * La tabla `report_exports` (**RF-IN-06**, decision 1 de la ficha 3.9).
 *
 * ## La exclusion mutua la resuelve el indice, no un `SELECT`
 *
 * `create()` inserta y **traduce** el choque contra
 * `report_exports_single_in_progress_uidx` en
 * {@see ReportExportAlreadyInProgress}. Comprobar antes con una consulta dejaria
 * pasar las dos pulsaciones de dos pestañas abiertas a la vez, que es exactamente
 * el caso que la restriccion existe para cerrar.
 *
 * Laravel 11+ levanta `UniqueConstraintViolationException` para el `23505` de
 * PostgreSQL, asi que no hace falta mirar el `SQLSTATE` a mano. Se comprueba
 * ademas el **nombre del indice**: en esta tabla hay otro unico —el de `uuid`— y
 * confundirlos convertiria «he generado dos veces el mismo UUID» en «ya tienes un
 * informe en curso», que es un mensaje falso.
 *
 * ## La lectura NO es tolerante
 *
 * Si algo va mal al leer, tiene que verse: una lista que fallara en silencio
 * seria un fichero con las horas de la plantilla que existe en el disco y no
 * aparece en ninguna pantalla.
 *
 * ## `save()` y no ocho metodos de marcar
 *
 * Las transiciones viven en {@see ReportExport} y son las que comprueban de donde
 * vienen. Aqui solo se escriben **las columnas mutables**: `uuid`, `kind`,
 * `format`, `parameters`, `scope` y el solicitante no se tocan nunca, y no
 * aparecen en el `UPDATE` para que no puedan cambiarse por descuido.
 *
 * El contador de descargas se escribe con el valor que trae el modelo y no con
 * `download_count + 1` de SQL: quien llama toma `FOR UPDATE` sobre la fila antes
 * (ver `lockByUuid()`), asi que no hay carrera que resolver con aritmetica
 * atomica — y el valor escrito es el mismo que se publico en el evento, que es lo
 * que hace que la fila y el asiento digan lo mismo.
 */
final readonly class DatabaseReportExportRepository implements ReportExportRepository
{
    private const string IN_PROGRESS_INDEX = 'report_exports_single_in_progress_uidx';

    private const string SELECT = <<<'SQL'
        SELECT e.id,
               e.uuid::text          AS uuid,
               e.kind,
               e.format,
               e.status,
               e.parameters::text    AS parameters,
               e.scope::text         AS scope,
               e.requested_by_user_id,
               u.uuid::text          AS requested_by_uuid,
               u.name                AS requested_by_name,
               e.requested_at,
               e.started_at,
               e.completed_at,
               e.failed_at,
               e.failure_reason,
               e.file_path,
               e.file_name,
               e.size_bytes,
               e.sha256,
               e.row_count,
               e.criteria::text      AS criteria,
               e.expires_at,
               e.purged_at,
               e.download_token_hash,
               e.download_token_expires_at,
               e.downloaded_at,
               e.download_count,
               e.notified_at,
               e.notification_channel
          FROM report_exports e
          LEFT JOIN users u ON u.id = e.requested_by_user_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        /**
         * Solo para `updated_at`.
         *
         * **Tambien aqui el reloj se inyecta** (regla dura 2). No es dogma: la
         * prueba que congela el reloj para comprobar que un enlace caduca a los
         * quince minutos leeria una fila cuyo `updated_at` viene del reloj de la
         * maquina, y la incoherencia entre las dos fechas de la misma fila es
         * justo lo que hace irreproducible una prueba de caducidad.
         */
        private Clock $clock,
    ) {}

    public function create(
        string $uuid,
        ReportExportKind $kind,
        string $format,
        ReportExportParameters $parameters,
        AccessScope $scope,
        array $criteria,
        int $requestedByUserId,
        DateTimeImmutable $requestedAt,
    ): ReportExport {
        try {
            $this->connection->table('report_exports')->insert([
                'uuid' => $uuid,
                'kind' => $kind->value,
                'format' => $format,
                'status' => ReportExportStatus::Pending->value,
                'parameters' => self::json($parameters->toArray()),
                'scope' => self::json(self::scopeToArray($scope)),
                'requested_by_user_id' => $requestedByUserId,
                'requested_at' => self::utc($requestedAt),
                'criteria' => self::json($criteria),
                'download_count' => 0,
                'created_at' => self::utc($requestedAt),
                'updated_at' => self::utc($requestedAt),
            ]);
        } catch (UniqueConstraintViolationException $collision) {
            if (! str_contains($collision->getMessage(), self::IN_PROGRESS_INDEX)) {
                throw $collision;
            }

            /*
             * **Sin consultar cual es la que ocupa el turno**, aunque el `409` la
             * necesite. PostgreSQL deja la transaccion en curso ABORTADA tras el
             * choque: cualquier `SELECT` aqui fallaria con `25P02` y el cliente
             * recibiria un `500` en lugar del `409` que le dice que espere. La
             * relectura la hace `RequestReportExport` cuando la transaccion ya se
             * ha deshecho.
             */
            throw new ReportExportAlreadyInProgress;
        }

        return $this->findByUuid($uuid)
            ?? throw new RuntimeException('El informe en diferido recien creado no se puede releer: '.$uuid);
    }

    public function findByUuid(string $uuid): ?ReportExport
    {
        $rows = $this->connection->select(self::SELECT.' WHERE e.uuid = CAST(? AS uuid)', [$uuid]);

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function lockByUuid(string $uuid): ?ReportExport
    {
        /*
         * `FOR UPDATE OF e` y no `FOR UPDATE` a secas: la consulta lleva un `LEFT
         * JOIN` con `users` y PostgreSQL rechaza bloquear el lado nulable de un
         * join externo. Lo que hay que serializar es la fila de la exportacion,
         * que es donde vive el token de un solo uso.
         */
        $rows = $this->connection->select(
            self::SELECT.' WHERE e.uuid = CAST(? AS uuid) FOR UPDATE OF e',
            [$uuid],
        );

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function findByUuidFor(string $uuid, int $requestedByUserId): ?ReportExport
    {
        // El dueño entra EN LA CONSULTA (decision 2): la exportacion de otra
        // persona no existe para quien pregunta, y eso es lo que produce el `404`
        // del contrato en lugar de un `403` que confirmaria que existe.
        $rows = $this->connection->select(
            self::SELECT.' WHERE e.uuid = CAST(? AS uuid) AND e.requested_by_user_id = ?',
            [$uuid, $requestedByUserId],
        );

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function lockByUuidFor(string $uuid, int $requestedByUserId): ?ReportExport
    {
        // El dueño y el bloqueo en la MISMA consulta: dos dejarian una ventana
        // entre comprobar de quien es la fila y quedarse con ella. `FOR UPDATE OF
        // e` por lo mismo que arriba —PostgreSQL rechaza bloquear el lado nulable
        // de un `LEFT JOIN`—.
        $rows = $this->connection->select(
            self::SELECT.' WHERE e.uuid = CAST(? AS uuid) AND e.requested_by_user_id = ? FOR UPDATE OF e',
            [$uuid, $requestedByUserId],
        );

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function inProgressFor(int $requestedByUserId): ?ReportExport
    {
        $rows = $this->connection->select(
            self::SELECT." WHERE e.requested_by_user_id = ? AND e.status IN ('pending', 'running')"
            .' ORDER BY e.id DESC LIMIT 1',
            [$requestedByUserId],
        );

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function recentFor(int $requestedByUserId, int $limit): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(
            self::SELECT.' WHERE e.requested_by_user_id = ?'
            .' ORDER BY e.requested_at DESC, e.id DESC LIMIT '.max(1, $limit),
            [$requestedByUserId],
        );

        return $this->hydrateAll($rows);
    }

    public function save(ReportExport $export): void
    {
        $this->connection->table('report_exports')->where('id', $export->id)->update([
            'status' => $export->status->value,
            /*
             * **Los dos unicos campos «inmutables» que si se escriben**, y solo
             * por la purga: al borrar el fichero, la fila se minimiza (RL-11) y
             * pierde el alcance y los dos filtros que señalan a alguien. Ver
             * `ReportExport::purge()`.
             *
             * Escribirlos siempre —en lugar de tener un `markPurged()` aparte— es
             * lo que hace que la fila de la base de datos sea exactamente lo que
             * dice el modelo, sin un camino que pueda olvidarse de minimizar.
             */
            'parameters' => self::json($export->parameters->toArray()),
            'scope' => $export->scope === null ? null : self::json(self::scopeToArray($export->scope)),
            'started_at' => self::nullableUtc($export->startedAt),
            'completed_at' => self::nullableUtc($export->completedAt),
            'failed_at' => self::nullableUtc($export->failedAt),
            // Un codigo del catalogo, nunca la clase ni el mensaje de la
            // excepcion: esta columna se serializa en la API (regla dura 21).
            'failure_reason' => $export->failureReason?->value,
            'file_path' => $export->filePath,
            'file_name' => $export->fileName,
            'size_bytes' => $export->sizeBytes,
            'sha256' => $export->sha256,
            'row_count' => $export->rowCount,
            'criteria' => self::json($export->criteria),
            'expires_at' => self::nullableUtc($export->expiresAt),
            'purged_at' => self::nullableUtc($export->purgedAt),
            'download_token_hash' => $export->downloadTokenHash,
            'download_token_expires_at' => self::nullableUtc($export->downloadTokenExpiresAt),
            'downloaded_at' => self::nullableUtc($export->downloadedAt),
            'download_count' => $export->downloadCount,
            'notified_at' => self::nullableUtc($export->notifiedAt),
            'notification_channel' => $export->notificationChannel?->value,
            'updated_at' => self::utc($this->clock->now()),
        ]);
    }

    public function recordNotification(
        int $id,
        DateTimeImmutable $notifiedAt,
        ReportExportNotificationChannel $channel,
    ): void {
        // Tres columnas y ninguna mas. Ver el docblock del puerto: el aviso ocurre
        // fuera de transaccion y despues de cerrar la fila, asi que un `save()` de
        // diecinueve columnas con la instancia de antes del correo desharia una
        // descarga o una purga que hubieran ocurrido mientras tanto.
        $this->connection->table('report_exports')->where('id', $id)->update([
            'notified_at' => self::utc($notifiedAt),
            'notification_channel' => $channel->value,
            'updated_at' => self::utc($notifiedAt),
        ]);
    }

    public function uuidsWithFile(): array
    {
        /** @var list<string> $uuids */
        $uuids = $this->connection->table('report_exports')
            ->where('status', ReportExportStatus::Completed->value)
            ->whereNull('purged_at')
            ->pluck('uuid')
            ->map(static fn (mixed $uuid): string => \is_string($uuid) ? $uuid : '')
            ->all();

        return $uuids;
    }

    public function failStale(DateTimeImmutable $staleBefore, DateTimeImmutable $now): int
    {
        /*
         * `COALESCE(started_at, requested_at)` cubre los dos atascos con una sola
         * condicion: la fila `running` cuyo trabajador murio a mitad, y la
         * `pending` que nadie llego a recoger porque la cola estaba parada.
         * Escribirlos como dos `WHERE` distintos daria dos umbrales que algun dia
         * dejarian de coincidir.
         */
        return $this->connection->table('report_exports')
            ->whereIn('status', [ReportExportStatus::Pending->value, ReportExportStatus::Running->value])
            ->whereRaw('COALESCE(started_at, requested_at) <= ?', [self::utc($staleBefore)])
            ->update([
                'status' => ReportExportStatus::Failed->value,
                'failed_at' => self::utc($now),
                'failure_reason' => ReportExportFailure::Stale->value,
                'updated_at' => self::utc($now),
            ]);
    }

    public function expired(DateTimeImmutable $now): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(
            self::SELECT." WHERE e.purged_at IS NULL AND e.status = 'completed' AND e.expires_at <= ? ORDER BY e.id",
            [self::utc($now)],
        );

        return $this->hydrateAll($rows);
    }

    /**
     * @param  list<object>  $rows
     * @return list<ReportExport>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (object $row): ReportExport => $this->hydrate(Row::of($row)), $rows);
    }

    private function hydrate(Row $row): ReportExport
    {
        return new ReportExport(
            id: $row->int('id'),
            uuid: $row->string('uuid'),
            kind: ReportExportKind::from($row->string('kind')),
            format: $row->string('format'),
            status: ReportExportStatus::from($row->string('status')),
            parameters: ReportExportParameters::fromArray($row->json('parameters') ?? []),
            // Nulo en las filas ya purgadas (RL-11): ahi el alcance se borro con
            // el fichero. `json()` devuelve nulo tanto para `NULL` como para un
            // JSON ilegible, y los dos casos se tratan igual — «no hay alcance».
            scope: self::scopeFromNullableArray($row->json('scope')),
            requestedByUserId: $row->int('requested_by_user_id'),
            requestedByUuid: $row->nullableString('requested_by_uuid'),
            requestedByName: $row->nullableString('requested_by_name'),
            requestedAt: $row->instant('requested_at'),
            startedAt: $row->nullableInstant('started_at'),
            completedAt: $row->nullableInstant('completed_at'),
            failedAt: $row->nullableInstant('failed_at'),
            failureReason: self::failureOf($row->nullableString('failure_reason')),
            filePath: $row->nullableString('file_path'),
            fileName: $row->nullableString('file_name'),
            sizeBytes: $row->nullableInt('size_bytes'),
            sha256: $row->nullableString('sha256'),
            rowCount: $row->nullableInt('row_count'),
            criteria: self::criteriaFrom($row->json('criteria') ?? []),
            expiresAt: $row->nullableInstant('expires_at'),
            purgedAt: $row->nullableInstant('purged_at'),
            downloadTokenHash: $row->nullableString('download_token_hash'),
            downloadTokenExpiresAt: $row->nullableInstant('download_token_expires_at'),
            downloadedAt: $row->nullableInstant('downloaded_at'),
            downloadCount: $row->int('download_count'),
            notifiedAt: $row->nullableInstant('notified_at'),
            notificationChannel: self::channelOf($row->nullableString('notification_channel')),
        );
    }

    /**
     * El alcance como `jsonb`.
     *
     * Dos formas y no una lista con un centinela: `{"unrestricted": true}` dice
     * «todo» y `{"departments": [...]}` dice «estos». Una lista vacia
     * significaria «nadie», que es un estado legitimo —un responsable sin
     * departamentos asignados— y distinguirlo de «todo» con la misma clave seria
     * la via por la que un informe acabaria sirviendo la plantilla entera.
     *
     * @return array<string, mixed>
     */
    private static function scopeToArray(AccessScope $scope): array
    {
        return $scope->isUnrestricted()
            ? ['unrestricted' => true]
            : ['departments' => $scope->departmentIds()];
    }

    /**
     * El alcance de la fila, o **nulo si ya se purgo** (RL-11).
     *
     * `null` y `[]` no son lo mismo y por eso hay dos metodos: aqui nulo significa
     * «esta fila ya no guarda a quien alcanzaba», y en
     * {@see self::scopeFromArray()} un array sin departamentos significa «no
     * alcanzaba a nadie», que es un alcance legitimo y muy distinto.
     *
     * @param  array<array-key, mixed>|null  $stored
     */
    private static function scopeFromNullableArray(?array $stored): ?AccessScope
    {
        return $stored === null ? null : self::scopeFromArray($stored);
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    private static function scopeFromArray(array $stored): AccessScope
    {
        if (($stored['unrestricted'] ?? false) === true) {
            return AccessScope::unrestricted();
        }

        $departments = $stored['departments'] ?? [];

        if (! \is_array($departments)) {
            // Fila ilegible: **nadie**, nunca «todo». Fallar cerrado es lo unico
            // admisible cuando lo que esta en juego es a cuantas personas alcanza
            // un informe (RF-ID-03).
            return AccessScope::forDepartments();
        }

        $ids = [];

        foreach ($departments as $department) {
            if (is_numeric($department)) {
                $ids[] = (int) $department;
            }
        }

        return AccessScope::forDepartments(...$ids);
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @return list<string>
     */
    private static function criteriaFrom(array $stored): array
    {
        $lines = [];

        foreach ($stored as $line) {
            if (\is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * El motivo del fallo, del catalogo.
     *
     * **Tolerante a proposito, al contrario que el resto de esta clase.** Una fila
     * escrita por una version anterior —o a mano, en una madrugada de
     * incidencia— puede llevar un valor que este binario no conoce; ahi la
     * respuesta correcta es `unexpected` y no romper el listado entero, porque lo
     * que quien pidio el informe necesita ver es que fallo. El `CHECK` de la
     * migracion hace que ese caso no pueda ocurrir por el camino normal.
     */
    private static function failureOf(?string $stored): ?ReportExportFailure
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return ReportExportFailure::tryFrom($stored) ?? ReportExportFailure::Unexpected;
    }

    private static function channelOf(?string $stored): ?ReportExportNotificationChannel
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        // Tolerante por lo mismo que el motivo del fallo, y con el mismo
        // desenlace conservador: ante un valor desconocido, «el aviso fue por la
        // pantalla», que es el canal que siempre existe.
        return ReportExportNotificationChannel::tryFrom($stored) ?? ReportExportNotificationChannel::Panel;
    }

    /**
     * @param  array<array-key, mixed>|list<string>  $value
     */
    private static function json(array $value): string
    {
        // Sin `JSON_THROW_ON_ERROR` no habria forma de distinguir un fallo de
        // codificacion de un `{}` legitimo, y la fila quedaria sin parametros sin
        // que nadie se enterase.
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function nullableUtc(?DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : self::utc($instant);
    }

    /** Regla dura 3: a la columna `TIMESTAMPTZ` se escribe siempre UTC. */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
