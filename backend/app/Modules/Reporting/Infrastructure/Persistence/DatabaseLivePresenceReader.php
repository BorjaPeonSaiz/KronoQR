<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see LivePresenceReader} sobre PostgreSQL (RF-PA-01, RF-PA-02).
 *
 * ## Dos consultas planas y ninguna por fila
 *
 * Una para los recuentos y otra para las filas. Se separan porque el filtro de
 * situacion se aplica a las segundas y **no** a los primeros: el panel enseña
 * «12 presentes / 40 ausentes» habiendo pedido una sola lista, y con una unica
 * consulta filtrada los recuentos desaparecerian justo cuando la lista se queda
 * vacia, que es cuando mas falta hacen.
 *
 * El nombre del empleado, el de su departamento y el del quiosco salen en la
 * misma consulta que la fila: preguntarlos despues, uno por uno, es el problema
 * N+1 en la pantalla que mas se abre en un cambio de turno.
 *
 * ## Se apoya en el indice parcial de turnos abiertos
 *
 * `one_open_shift_per_employee` —`UNIQUE (employee_id) WHERE clocked_out_at IS
 * NULL AND status NOT IN ('voided','superseded')`, migracion inicial— es el
 * indice que el doc 02 §3.2 puso ahi para esto: *«quien esta dentro ahora se
 * resuelve en O(log n) sin escanear el historico»*. El `LEFT JOIN` repite su
 * predicado **literalmente** para que PostgreSQL pueda usarlo, y de paso hereda
 * su garantia: al ser UNIQUE, la union no puede duplicar a nadie.
 *
 * El quiosco de origen se resuelve con un `LATERAL` sobre `scan_events` apoyado
 * en `scan_events_clock_in_shift_entry_index`, que es parcial sobre
 * `result = 'clock_in'`: sin el, un hotel con dos años de escaneos recorreria la
 * tabla entera para pintar una columna.
 *
 * ## El alcance entra en el `WHERE`
 *
 * RF-ID-03. Nunca se filtra la lista ya traida: si se hiciera, los recuentos
 * describirian a personas que quien pregunta no puede ver. Y un alcance que no
 * alcanza a nadie —un responsable sin departamentos asignados— se traduce en un
 * predicado imposible y no en «sin filtro», que es el fallo que convertiria a un
 * responsable recien creado en alguien que ve el hotel entero.
 *
 * ## Solo quien esta de alta
 *
 * `employees.status = 'active'`. Es lo contrario del criterio de `GET
 * /employees`, donde el historico se conserva a la vista (RF-GP-03, RL-02), y la
 * diferencia es deliberada: la pregunta de esta vista es quien esta trabajando,
 * y alguien que ya no pertenece a la plantilla no es un ausente.
 */
final readonly class DatabaseLivePresenceReader implements LivePresenceReader
{
    /** ADR-026: los dos estados que no cuentan como vigentes. */
    private const string OPEN_SHIFT_JOIN = <<<'SQL'
        LEFT JOIN shift_entries se
               ON se.employee_id = e.id
              AND se.clocked_out_at IS NULL
              AND se.status NOT IN ('voided', 'superseded')
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function board(
        AccessScope $scope,
        ?int $departmentId,
        ?string $search,
        PresenceStatus $status,
        DateTimeImmutable $generatedAt,
        string $timeZone,
    ): PresenceBoard {
        [$filters, $bindings] = $this->filters($scope, $departmentId, $search);

        $counts = $this->counts($filters, $bindings);

        $rows = $this->rows(
            $filters.($status === PresenceStatus::Present ? ' AND se.id IS NOT NULL' : ' AND se.id IS NULL'),
            $bindings,
        );

        return new PresenceBoard(
            entries: $rows,
            presentCount: $counts['present'],
            absentCount: $counts['absent'],
            generatedAt: $generatedAt,
            timeZone: $timeZone,
        );
    }

    public function stateOf(string $employeeUuid): ?PresenceEntry
    {
        $rows = $this->rows(' AND e.uuid = ?', [$employeeUuid]);

        return $rows[0] ?? null;
    }

    public function openShiftsByDepartment(): array
    {
        // `e.status = 'active'` tambien aqui, y no es un detalle: la metrica y el
        // panel tienen que contestar lo mismo a «cuanta gente hay dentro». Un
        // tramo abierto a nombre de alguien ya cesado es una anomalia que abre
        // incidencia (RN-14, tarea 2.6), no una persona trabajando.
        // **Se enumeran los departamentos, no los turnos.** Una cocina sin nadie
        // dentro tiene que publicar un cero y no desaparecer: en Prometheus, una
        // serie que se esfuma es indistinguible de una que nunca existio, y ahi
        // el cero es justo el valor que alguien esta mirando a las 06:00. Por eso
        // la consulta parte de `departments` y no de `shift_entries`.
        //
        // La segunda mitad de la union es el cubo de quien no tiene departamento:
        // sin el, la suma del gauge no cuadraria con la gente que hay dentro.
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<SQL
            SELECT d.name AS department, count(se.id) AS open_shifts
              FROM departments d
              LEFT JOIN employees e
                     ON e.department_id = d.id
                    AND e.status = 'active'
              {$this->openShiftJoin()}
             GROUP BY d.name
             UNION ALL
            SELECT '' AS department, count(se.id) AS open_shifts
              FROM employees e
              {$this->openShiftJoin()}
             WHERE e.department_id IS NULL
               AND e.status = 'active'
            SQL);

        $totals = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);

            $totals[$reader->string('department')] = $reader->int('open_shifts');
        }

        return $totals;
    }

    /**
     * Los predicados comunes a las dos consultas, con sus parametros enlazados.
     *
     * Va aparte y no repetido para que el alcance, el departamento y la busqueda
     * no puedan diferir entre las filas y sus recuentos: si se escribieran dos
     * veces, bastaria tocar una para que el panel enseñara doce nombres y dijera
     * que hay catorce.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function filters(AccessScope $scope, ?int $departmentId, ?string $search): array
    {
        $sql = '';

        /** @var list<scalar> $bindings */
        $bindings = [];

        if (! $scope->isUnrestricted()) {
            if ($scope->reachesNobody()) {
                // Un responsable sin departamentos asignados no alcanza a nadie.
                // El predicado imposible es la traduccion literal de eso; «sin
                // filtro» seria la plantilla entera (RF-ID-03).
                $sql .= ' AND 1 = 0';
            } else {
                $ids = $scope->departmentIds();

                $sql .= ' AND e.department_id IN ('.implode(', ', array_fill(0, \count($ids), '?')).')';
                $bindings = [...$bindings, ...$ids];
            }
        }

        if ($departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $bindings[] = $departmentId;
        }

        if ($search !== null) {
            // Los mismos cuatro campos y el mismo tratamiento de acentos que
            // `GET /employees` (RF-GP-01): quien escribe en el cuadro de una
            // pantalla del panel espera lo mismo en la de al lado. `unaccent()`
            // a los dos lados porque `ILIKE` ignora mayusculas pero no
            // diacriticos, y `%`, `_` y `\` se escapan para que no sean
            // comodines que nadie ha contado al usuario.
            $pattern = '%'.addcslashes($search, '\\%_').'%';

            $sql .= <<<'SQL'
                 AND (
                        unaccent(e.first_name) ILIKE unaccent(?)
                     OR unaccent(e.last_name) ILIKE unaccent(?)
                     OR unaccent(e.employee_code::text) ILIKE unaccent(?)
                     OR unaccent(e.first_name || ' ' || e.last_name) ILIKE unaccent(?)
                 )
                SQL;

            $bindings = [...$bindings, $pattern, $pattern, $pattern, $pattern];
        }

        return [$sql, $bindings];
    }

    /**
     * @param  list<scalar>  $bindings
     * @return array{present: int, absent: int}
     */
    private function counts(string $filters, array $bindings): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<SQL
            SELECT count(*) FILTER (WHERE se.id IS NOT NULL) AS present_count,
                   count(*) FILTER (WHERE se.id IS NULL)     AS absent_count
              FROM employees e
              {$this->openShiftJoin()}
             WHERE e.status = 'active'{$filters}
            SQL, $bindings);

        if ($rows === []) {
            return ['present' => 0, 'absent' => 0];
        }

        $reader = Row::of($rows[0]);

        return ['present' => $reader->int('present_count'), 'absent' => $reader->int('absent_count')];
    }

    /**
     * @param  list<scalar>  $bindings
     * @return list<PresenceEntry>
     */
    private function rows(string $filters, array $bindings): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<SQL
            SELECT e.uuid                AS employee_uuid,
                   e.first_name,
                   e.last_name,
                   d.id                  AS department_id,
                   d.name                AS department_name,
                   se.uuid               AS shift_entry_uuid,
                   se.clocked_in_at,
                   se.clock_in_source,
                   dev.uuid              AS device_uuid,
                   dev.name              AS device_name
              FROM employees e
              {$this->openShiftJoin()}
              LEFT JOIN departments d ON d.id = e.department_id
              LEFT JOIN LATERAL (
                  SELECT sc.device_id
                    FROM scan_events sc
                   WHERE sc.shift_entry_id = se.id
                     AND sc.result = 'clock_in'
                   ORDER BY sc.occurred_at DESC
                   LIMIT 1
              ) clock_in ON TRUE
              LEFT JOIN devices dev ON dev.id = clock_in.device_id
             WHERE e.status = 'active'{$filters}
             ORDER BY lower(e.last_name), lower(e.first_name), e.uuid
            SQL, $bindings);

        return array_map(static function (object $row): PresenceEntry {
            $reader = Row::of($row);

            $shiftEntryUuid = $reader->nullableString('shift_entry_uuid');

            return new PresenceEntry(
                employeeUuid: $reader->string('employee_uuid'),
                fullName: trim($reader->string('first_name').' '.$reader->string('last_name')),
                departmentId: $reader->nullableInt('department_id'),
                departmentName: $reader->nullableString('department_name'),
                status: $shiftEntryUuid === null ? PresenceStatus::Absent : PresenceStatus::Present,
                shiftEntryUuid: $shiftEntryUuid,
                clockedInAt: $reader->nullableInstant('clocked_in_at'),
                origin: $reader->nullableString('clock_in_source'),
                deviceUuid: $reader->nullableString('device_uuid'),
                deviceName: $reader->nullableString('device_name'),
            );
        }, $rows);
    }

    /**
     * El `LEFT JOIN` con el predicado del indice parcial, escrito una sola vez.
     *
     * Que las tres consultas usen la MISMA definicion de «turno abierto» no es
     * comodidad: es lo que impide que el listado, sus recuentos y la metrica
     * `open_shifts_current` respondan cosas distintas a la misma pregunta.
     */
    private function openShiftJoin(): string
    {
        return self::OPEN_SHIFT_JOIN;
    }
}
