<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ComplianceFactsReader;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceEmployee;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceShiftSegment;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummaryQuery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceWeek;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * {@see ComplianceFactsReader} sobre PostgreSQL (RF-PA-06).
 *
 * ## Lo que NO hace, que es lo primero
 *
 * **No decide nada.** No hay ni un umbral ni una comparacion en este fichero: se
 * traen hechos y el dominio los juzga (reglas duras 1 y 2). Es lo que permite que
 * la vista y la bandeja usen los mismos predicados y por tanto cuenten lo mismo.
 *
 * **No recalcula el total del dia.** `total_minutes` sale de `daily_totals`, la
 * proyeccion reconstruible de RN-06 (regla dura 7, ADR-007). Sumar
 * `shift_entries` aqui seria una segunda forma de calcular el mismo numero.
 *
 * **No parte ningun turno.** Se agrupa por `work_date`, que ya es la fecha civil
 * del centro con RN-05 aplicada: el 22:00 → 06:00 pertenece entero al dia en que
 * empezo (ADR-006, regla dura 4). **No hay un solo `AT TIME ZONE` en esta
 * consulta**, y esa ausencia es la garantia.
 *
 * ## Por que el rango que se lee es mas ancho que el pedido
 *
 * Dos ampliaciones, las dos obligatorias para que la respuesta signifique algo:
 *
 *   - **Hacia atras, hasta la ultima jornada anterior a `from`.** RN-10 mide el
 *     hueco con la jornada anterior, asi que sin ella el primer dia de cualquier
 *     ventana no se evaluaria — y no fallaria nada: el aviso simplemente no
 *     saldria. Se resuelve con `lag()` sobre las jornadas de cada persona.
 *   - **Hacia los dos lados, hasta completar las semanas del borde.** Toda semana
 *     que toque el rango se evalua sobre sus siete dias (decision 6). Lo calcula
 *     {@see ComplianceWeek} con `week_starts_on`, no un `date_trunc('week')`, que
 *     solo sabe empezar en lunes.
 *
 * La ampliacion por descanso es **una fila mas por persona**, no un dia mas de
 * calendario: entre dos jornadas puede haber un mes de vacaciones, y leer «el dia
 * anterior» daria un descanso nulo justo cuando mas evidente es que se cumplio.
 *
 * ## `lag()` y no una subconsulta correlacionada
 *
 * Una funcion de ventana sobre `daily_totals` ordenada por persona y fecha
 * resuelve las quinientas jornadas anteriores en la misma pasada. La alternativa
 * —un `LATERAL` por fila buscando la jornada anterior— es una busqueda por indice
 * por cada dia de cada persona: cuarenta y cinco mil en una vista de tres meses.
 *
 * ## Solo tramos VIGENTES
 *
 * `closed` y `anomalous` para lo que se mide; `open` marca la jornada y aporta
 * cero; `voided` y `superseded` **nunca** (regla dura 5: la version corregida es
 * la que vale, y la anterior se conserva pero no cuenta).
 *
 * ## El alcance entra en el `WHERE` y el techo de tiempo lo pone PostgreSQL
 *
 * RF-ID-03 y el mismo `SET LOCAL statement_timeout` del informe por periodo, con
 * su misma traduccion a `422`: asi la consulta se cancela **en el servidor** y
 * libera la conexion que atiende el fichaje (RNF-P-02, regla dura 19).
 */
final readonly class DatabaseComplianceFactsReader implements ComplianceFactsReader
{
    /** `query_canceled`: el `SQLSTATE` con el que PostgreSQL corta por `statement_timeout`. */
    private const string QUERY_CANCELED = '57014';

    public function __construct(
        private ConnectionInterface $connection,
        /** Segundos de `statement_timeout` de esta consulta (`config/reporting.php`). */
        private int $timeoutSeconds,
    ) {}

    public function factsFor(ComplianceSummaryQuery $query, int $weekStartsOn): array
    {
        [$window, $windowEnd] = $this->window($query, $weekStartsOn);
        [$filters, $bindings] = $this->subjectFilters($query);

        $rows = $this->withStatementTimeout(fn (): array => $this->select(
            $this->sql($filters),
            // En el orden en que aparecen en el texto: los filtros de `subjects`
            // —una sola vez, porque las dos ramas del `UNION ALL` parten de esa
            // CTE—, la ventana, y el corte de la jornada anterior a la ventana.
            [...$bindings, $window, $windowEnd, $window],
        ));

        return array_map($this->toFacts(...), $rows);
    }

    /**
     * La ventana de lectura: `[primer dia de la primera semana, ultimo dia de la
     * ultima]`.
     *
     * La jornada anterior a `from` **no** entra por aqui —la resuelve `lag()`, que
     * no necesita saber cuanto hay que retroceder—, pero el primer dia de la
     * semana del borde si, y puede estar hasta seis dias antes de `from`.
     *
     * @return array{0: string, 1: string}
     */
    private function window(ComplianceSummaryQuery $query, int $weekStartsOn): array
    {
        return [
            ComplianceWeek::containing($query->range->isoFrom(), $weekStartsOn)->startsOn,
            ComplianceWeek::containing($query->range->isoTo(), $weekStartsOn)->endsOn,
        ];
    }

    /**
     * Una fila por jornada con actividad, con el fin de la jornada anterior y el
     * tramo cerrado mas largo.
     *
     * Se compone con constantes de esta clase y **nunca con entrada del cliente**:
     * fechas, departamento, UUID y los identificadores del alcance viajan como
     * parametros enlazados. Un identificador SQL no admite `?`, asi que la unica
     * defensa posible es que no haya ninguno que dependa del cliente.
     */
    private function sql(string $filters): string
    {
        return <<<SQL
            WITH subjects AS (
                SELECT e.id                  AS employee_id,
                       e.uuid                AS employee_uuid,
                       e.employee_code::text AS employee_code,
                       e.first_name,
                       e.last_name,
                       dep.id                AS department_id,
                       dep.name              AS department_name
                  FROM employees e
                  LEFT JOIN departments dep ON dep.id = e.department_id
                 WHERE TRUE{$filters}
            ), scanned AS (
                -- Las jornadas de la ventana...
                SELECT s.employee_id, s.employee_uuid, s.employee_code, s.first_name, s.last_name,
                       s.department_id, s.department_name,
                       dt.work_date, dt.total_minutes, dt.has_open_shift,
                       dt.first_in_at, dt.last_out_at,
                       TRUE AS in_window
                  FROM subjects s
                  JOIN daily_totals dt
                    ON dt.employee_id = s.employee_id
                   AND dt.work_date BETWEEN ?::date AND ?::date

                UNION ALL

                -- ...mas UNA jornada por persona **anterior a la ventana**, que es
                -- la que le da su descanso a la primera del rango. Se trae con un
                -- `LATERAL … LIMIT 1` sobre el indice `(employee_id, work_date)` y
                -- no ampliando el calendario: entre dos jornadas puede haber un mes
                -- de vacaciones, y «el dia anterior» daria un descanso nulo justo
                -- cuando mas evidente es que se cumplio.
                SELECT s.employee_id, s.employee_uuid, s.employee_code, s.first_name, s.last_name,
                       s.department_id, s.department_name,
                       prev.work_date, prev.total_minutes, prev.has_open_shift,
                       prev.first_in_at, prev.last_out_at,
                       FALSE AS in_window
                  FROM subjects s
                  JOIN LATERAL (
                      SELECT dt.work_date, dt.total_minutes, dt.has_open_shift,
                             dt.first_in_at, dt.last_out_at
                        FROM daily_totals dt
                       WHERE dt.employee_id = s.employee_id
                         AND dt.work_date < ?::date
                       ORDER BY dt.work_date DESC
                       LIMIT 1
                  ) AS prev ON TRUE
            ), days AS (
                SELECT scanned.*,
                       -- El fin de la jornada ANTERIOR de esta misma persona.
                       lag(scanned.last_out_at) OVER (
                           PARTITION BY scanned.employee_id ORDER BY scanned.work_date
                       ) AS previous_last_out_at
                  FROM scanned
            ), windowed AS (
                SELECT * FROM days WHERE in_window
            )
            SELECT w.*,
                   opening.uuid          AS opening_shift_entry_uuid,
                   longest.uuid          AS longest_shift_entry_uuid,
                   longest.clocked_in_at AS longest_clocked_in_at,
                   longest.clocked_out_at AS longest_clocked_out_at
              FROM windowed w
              -- El tramo que ABRE la jornada: el de entrada mas temprana. Es el
              -- que RN-10 señala, porque es el que empezo antes de tiempo.
              LEFT JOIN LATERAL (
                  SELECT se.uuid
                    FROM shift_entries se
                   WHERE se.employee_id = w.employee_id
                     AND se.work_date = w.work_date
                     AND se.status IN ('open', 'closed', 'anomalous')
                   ORDER BY se.clocked_in_at, se.id
                   LIMIT 1
              ) AS opening ON TRUE
              -- Y el tramo CERRADO mas largo, que es el unico que puede superar el
              -- maximo continuo de RN-12: traer los demas seria cargar la
              -- plantilla entera para mirar una fila por dia.
              LEFT JOIN LATERAL (
                  SELECT se.uuid, se.clocked_in_at, se.clocked_out_at
                    FROM shift_entries se
                   WHERE se.employee_id = w.employee_id
                     AND se.work_date = w.work_date
                     AND se.status IN ('closed', 'anomalous')
                     AND se.clocked_out_at IS NOT NULL
                   ORDER BY (se.clocked_out_at - se.clocked_in_at) DESC, se.id
                   LIMIT 1
              ) AS longest ON TRUE
             ORDER BY lower(w.last_name), lower(w.first_name), w.employee_uuid, w.work_date
            SQL;
    }

    private function toFacts(object $row): ComplianceFacts
    {
        $reader = Row::of($row);

        $longestUuid = $reader->nullableString('longest_shift_entry_uuid');
        $longestIn = $reader->nullableInstant('longest_clocked_in_at');
        $longestOut = $reader->nullableInstant('longest_clocked_out_at');

        return new ComplianceFacts(
            employee: new ComplianceEmployee(
                uuid: $reader->string('employee_uuid'),
                employeeCode: $reader->string('employee_code'),
                firstName: $reader->string('first_name'),
                lastName: $reader->string('last_name'),
                departmentId: $reader->nullableInt('department_id'),
                departmentName: $reader->nullableString('department_name'),
            ),
            workDate: substr($reader->string('work_date'), 0, 10),
            firstInAt: $reader->nullableInstant('first_in_at'),
            lastOutAt: $reader->nullableInstant('last_out_at'),
            previousLastOutAt: $reader->nullableInstant('previous_last_out_at'),
            totalMinutes: $reader->int('total_minutes'),
            hasOpenShift: $reader->bool('has_open_shift'),
            longestClosedSegment: $longestUuid !== null
                && $longestIn instanceof DateTimeImmutable
                && $longestOut instanceof DateTimeImmutable
                    ? new ComplianceShiftSegment($longestUuid, $longestIn, $longestOut)
                    : null,
            openingShiftEntryUuid: $reader->nullableString('opening_shift_entry_uuid'),
        );
    }

    /**
     * Los predicados sobre `employees`, con sus parametros.
     *
     * **Toda la plantilla del alcance, incluida la que ya no esta** (RN-14,
     * RF-GP-03): el cumplimiento de marzo de quien causo baja el 20 de marzo
     * sigue siendo cumplimiento de marzo. Es el mismo criterio del informe por
     * periodo y el contrario al del panel de presencia.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function subjectFilters(ComplianceSummaryQuery $query): array
    {
        [$sql, $bindings] = $this->scopePredicate($query->scope);

        if ($query->departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $bindings[] = $query->departmentId;
        }

        if ($query->employeeUuid !== null) {
            $sql .= ' AND e.uuid = ?';
            $bindings[] = $query->employeeUuid;
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function scopePredicate(AccessScope $scope): array
    {
        if ($scope->isUnrestricted()) {
            return ['', []];
        }

        if ($scope->reachesNobody()) {
            // Un responsable sin departamentos asignados no alcanza a nadie. El
            // predicado imposible es la traduccion literal de eso; «sin filtro»
            // seria la plantilla entera (RF-ID-03).
            return [' AND 1 = 0', []];
        }

        $ids = $scope->departmentIds();

        return [' AND e.department_id IN ('.implode(', ', array_fill(0, \count($ids), '?')).')', $ids];
    }

    /**
     * @param  list<scalar>  $bindings
     * @return list<object>
     */
    private function select(string $sql, array $bindings): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select($sql, $bindings);

        return $rows;
    }

    /**
     * Ejecuta con el techo de tiempo puesto en el servidor.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     *
     * @throws ReportTooLargeForSynchronousDelivery cuando PostgreSQL cancela la consulta
     */
    private function withStatementTimeout(callable $run): mixed
    {
        try {
            return $this->connection->transaction(function () use ($run): mixed {
                // `SET LOCAL` acota el techo a ESTA transaccion: un
                // `statement_timeout` global cortaria migraciones y
                // reconciliaciones que legitimamente tardan mas.
                $this->connection->statement("SET LOCAL statement_timeout = '".$this->timeoutSeconds."s'");

                return $run();
            });
        } catch (QueryException $exception) {
            if ($this->wasCancelled($exception)) {
                throw ReportTooLargeForSynchronousDelivery::complianceTimedOut($this->timeoutSeconds);
            }

            throw $exception;
        }
    }

    private function wasCancelled(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && ($exception->errorInfo[0] ?? null) === self::QUERY_CANCELED;
    }
}
