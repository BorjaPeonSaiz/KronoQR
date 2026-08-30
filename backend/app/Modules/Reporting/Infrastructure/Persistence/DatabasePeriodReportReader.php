<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\PeriodReportReader;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\ReportSubject;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * {@see PeriodReportReader} sobre PostgreSQL (RF-IN-01, RF-IN-02, RF-IN-03).
 *
 * ## Lo que NO hace, que es lo primero
 *
 * **No recalcula nada.** Los minutos salen de `daily_totals.total_minutes`, que
 * es la proyeccion reconstruible de RN-06 (regla dura 7, ADR-007). No hay ni una
 * suma de `shift_entries` en este fichero, y no la puede haber: seria una segunda
 * forma de calcular el mismo total, y el dia que discreparan nadie sabria cual
 * creer. Si los numeros no cuadran, el problema esta en la proyeccion y se
 * arregla con `attendance:reconcile` (RF-PR-02).
 *
 * **No agrupa por `date_trunc('day', clocked_in_at)`.** Se agrupa por
 * `work_date`, que ya es la fecha civil del centro con RN-05 aplicada: el turno
 * 22:00 → 06:00 esta atribuido entero al dia en que empezo. Agrupar por el
 * instante en UTC lo partiria entre dos dias en invierno y lo moveria de dia en
 * verano. Por eso **en toda esta consulta no hay un solo `AT TIME ZONE`**, y esa
 * ausencia es la garantia.
 *
 * ## `generate_series`, porque los dias vacios cuentan
 *
 * El calendario se genera y se cruza con la plantilla **antes** de unir los
 * totales. Si se partiera de `daily_totals`, un dia sin fichajes no existiria y
 * el informe no distinguiria «no trabajo» de «no habia nadie»: para un informe
 * de absentismo, omitir los dias sin actividad es un error, y `/informe-nuevo`
 * lo dice por escrito. Como efecto util, `days_with_activity +
 * days_without_activity` es siempre `days_in_period`, y esa igualdad se puede
 * comprobar.
 *
 * ## Lo contratado se prorratea en la propia consulta
 *
 * `sum(weekly_hours) * 60 / 7` sobre los dias cubiertos es exactamente
 * `EmploymentContract::contractedMinutesForDays()` escrito en SQL, con el
 * redondeo **una sola vez al final** (redondear por dia y sumar acumularia media
 * hora de error en un mes). Que la formula exista en dos sitios esta asumido —el
 * dominio no puede agregar quinientas personas fila a fila— y lo ata una prueba
 * de integracion que compara las dos sobre el mismo caso.
 *
 * `60.0` y no `60`: en PostgreSQL, `60 / 7` entre enteros es `8`, y el informe
 * saldria con un 6 % de menos sin que nada fallara.
 *
 * ## El alcance entra en el `WHERE`
 *
 * RF-ID-03. Nunca se filtra un agregado ya calculado: el total por centro de
 * quien alcanza un solo departamento tiene que ser el total **de ese
 * departamento**. Y un alcance que no alcanza a nadie se traduce en un predicado
 * imposible y no en «sin filtro», que es el fallo que convertiria a un
 * responsable recien creado en alguien que ve el hotel entero.
 *
 * ## El techo de tiempo lo pone PostgreSQL
 *
 * `SET LOCAL statement_timeout` dentro de la transaccion de lectura, no un
 * cronometro en PHP: asi la consulta se cancela **en el servidor** y libera la
 * conexion. Medir en PHP el tiempo de algo que ya termino solo sirve para
 * escribirlo en el log, con la base de datos que atiende el fichaje ocupada
 * mientras tanto (RNF-P-02, regla dura 19). La cancelacion llega como `SQLSTATE
 * 57014` y se traduce al `422` que remite a la generacion en diferido de
 * RF-IN-06.
 *
 * ## Quien entra en el informe
 *
 * **Toda la plantilla del alcance, incluida la que ya no esta** (RN-14,
 * RF-GP-03, RL-02). Es lo contrario del panel de presencia, donde solo cuenta
 * quien esta de alta, y la diferencia es deliberada: la pregunta de aquella
 * vista es quien esta trabajando y la de esta es que horas se hicieron. Un
 * informe de marzo que se olvidara de quien causo baja el 20 de marzo daria un
 * total del departamento que no cuadra con ninguna nomina.
 */
final readonly class DatabasePeriodReportReader implements PeriodReportReader
{
    /** `query_canceled`: el `SQLSTATE` con el que PostgreSQL corta por `statement_timeout`. */
    private const string QUERY_CANCELED = '57014';

    public function __construct(
        private ConnectionInterface $connection,
        /** Segundos de `statement_timeout` de esta consulta (`config/reporting.php`). */
        private int $timeoutSeconds,
    ) {}

    public function estimateRows(PeriodReportQuery $query): int
    {
        [$filters, $bindings] = $this->subjectFilters($query);

        $subjects = match ($query->grouping) {
            // Un solo centro por instalacion (ADR-040): una fila por cubo.
            ReportGrouping::Site => 1,
            ReportGrouping::Department => $this->scalar(
                'SELECT count(*) FROM (SELECT DISTINCT e.department_id FROM employees e WHERE TRUE'
                .$filters.') AS departments',
                $bindings,
            ),
            ReportGrouping::Employee => $this->scalar(
                'SELECT count(*) FROM employees e WHERE TRUE'.$filters,
                $bindings,
            ),
        };

        return $subjects * $this->bucketCount($query);
    }

    public function rows(PeriodReportQuery $query, string $siteName): array
    {
        $rows = $this->withStatementTimeout(fn (): array => $this->select(
            $this->rowsSql($query),
            $this->rowsBindings($query),
        ));

        return array_map(
            fn (object $row): PeriodReportRow => $this->toRow($row, $query->grouping, $siteName),
            $rows,
        );
    }

    public function contractCoverage(PeriodReportQuery $query): ContractCoverage
    {
        [$filters, $bindings] = $this->subjectFilters($query);

        $rows = $this->withStatementTimeout(fn (): array => $this->select(<<<SQL
            WITH subjects AS (
                SELECT e.id            AS employee_id,
                       e.hired_at::date AS hired_on,
                       e.terminated_at::date AS terminated_on
                  FROM employees e
                 WHERE TRUE{$filters}
            ), calendar AS (
                SELECT generate_series(?::date, ?::date, interval '1 day')::date AS work_date
            )
            SELECT count(*)                     AS days_without_contract,
                   count(DISTINCT s.employee_id) AS employees_without_contract
              FROM subjects s
             CROSS JOIN calendar c
              LEFT JOIN employment_contracts ec
                     ON ec.employee_id = s.employee_id
                    AND c.work_date >= ec.valid_from
                    AND (ec.valid_to IS NULL OR c.work_date <= ec.valid_to)
                    -- Redundante para el resultado y decisivo para el plan, por
                    -- lo mismo que en la consulta de las filas: `c.work_date`
                    -- sale de `generate_series` y de ahi PostgreSQL no deduce
                    -- ningun rango con el que acotar la tabla.
                    AND ec.valid_from <= ?::date
                    AND (ec.valid_to IS NULL OR ec.valid_to >= ?::date)
             WHERE ec.id IS NULL
               -- Solo dias de relacion laboral: un dia anterior al alta no es un
               -- dia «sin contrato», es un dia en el que no trabajaba aqui.
               AND c.work_date >= s.hired_on
               AND (s.terminated_on IS NULL OR c.work_date <= s.terminated_on)
            SQL, [
            ...$bindings,
            // `calendar`.
            $query->range->isoFrom(),
            $query->range->isoTo(),
            // Los dos acotes del contrato, en el orden en que aparecen.
            $query->range->isoTo(),
            $query->range->isoFrom(),
        ]));

        if ($rows === []) {
            return new ContractCoverage(0, 0);
        }

        $reader = Row::of($rows[0]);

        return new ContractCoverage(
            daysWithoutContract: $reader->int('days_without_contract'),
            employeesWithoutContract: $reader->int('employees_without_contract'),
        );
    }

    /**
     * La consulta del informe, montada por partes segun lo que se ha pedido.
     *
     * Se compone con `match` y constantes de esta clase, **nunca con entrada del
     * cliente**: la granularidad y la agrupacion son enumerados ya validados, y
     * todo lo que viene de fuera —fechas, departamento, UUID, los
     * identificadores del alcance— viaja como parametro enlazado. Un
     * identificador SQL no admite `?`, asi que la unica defensa posible es que no
     * haya ninguno que dependa del cliente.
     */
    private function rowsSql(PeriodReportQuery $query): string
    {
        [$filters] = $this->subjectFilters($query);

        $bucket = $this->bucketExpressions($query->granularity);
        $counted = $query->includeOpenShifts ? 'TRUE' : 'NOT d.has_open_shift';
        $keys = $this->groupKeys($query->grouping);
        $select = $keys === '' ? '' : $keys.',';
        $groupBy = $keys === '' ? '' : $keys.',';

        return <<<SQL
            WITH subjects AS (
                SELECT e.id                  AS employee_id,
                       e.uuid                AS employee_uuid,
                       e.employee_code::text AS employee_code,
                       e.first_name,
                       e.last_name,
                       e.hired_at::date      AS hired_on,
                       e.terminated_at::date AS terminated_on,
                       dep.id                AS department_id,
                       dep.name              AS department_name
                  FROM employees e
                  LEFT JOIN departments dep ON dep.id = e.department_id
                 WHERE TRUE{$filters}
            ), calendar AS (
                SELECT generate_series(?::date, ?::date, interval '1 day')::date AS work_date
            ), grid AS (
                SELECT s.*,
                       c.work_date,
                       {$bucket['start']} AS period_start,
                       {$bucket['end']}   AS period_end
                  FROM subjects s
                 CROSS JOIN calendar c
            ), d AS (
                SELECT g.*,
                       COALESCE(dt.total_minutes, 0)      AS total_minutes,
                       COALESCE(dt.shift_count, 0)        AS shift_count,
                       COALESCE(dt.has_open_shift, FALSE) AS has_open_shift,
                       COALESCE(dt.has_incident, FALSE)   AS has_incident,
                       ec.weekly_hours,
                       (g.work_date >= g.hired_on
                        AND (g.terminated_on IS NULL OR g.work_date <= g.terminated_on)) AS employed
                  FROM grid g
                  LEFT JOIN daily_totals dt
                         ON dt.employee_id = g.employee_id
                        AND dt.work_date = g.work_date
                        -- REDUNDANTE PARA EL RESULTADO, DECISIVO PARA EL PLAN.
                        -- `dt.work_date = g.work_date` acota de sobra, pero
                        -- `g.work_date` sale de `generate_series` y PostgreSQL no
                        -- puede deducir de ahi ningun rango: sin esta linea
                        -- construye la tabla hash con `daily_totals` ENTERA, y con
                        -- cuatro años de retencion (RL-02) eso es cerca de un
                        -- millon de filas para leer las quince mil de un mes.
                        -- Medido: `Seq Scan on daily_totals` con 365.000 filas.
                        AND dt.work_date BETWEEN ?::date AND ?::date
                  LEFT JOIN employment_contracts ec
                         ON ec.employee_id = g.employee_id
                        AND g.work_date >= ec.valid_from
                        AND (ec.valid_to IS NULL OR g.work_date <= ec.valid_to)
                        -- Lo mismo para los contratos: solo los que solapan con el
                        -- periodo. Un empleado con diez contratos a lo largo de su
                        -- vida laboral aporta uno o dos a cualquier informe.
                        AND ec.valid_from <= ?::date
                        AND (ec.valid_to IS NULL OR ec.valid_to >= ?::date)
            )
            SELECT {$select}
                   d.period_start,
                   d.period_end,
                   count(*)                                              AS days_in_period,
                   count(*) FILTER (WHERE d.shift_count > 0)             AS days_with_activity,
                   count(*) FILTER (WHERE d.has_open_shift)              AS open_shift_days,
                   count(*) FILTER (WHERE d.has_incident)                AS incident_days,
                   COALESCE(sum(d.total_minutes) FILTER (WHERE {$counted}), 0) AS worked_minutes,
                   COALESCE(sum(d.shift_count) FILTER (WHERE {$counted}), 0)   AS total_shifts,
                   ROUND(COALESCE(sum(d.weekly_hours), 0) * 60.0 / 7)    AS contracted_minutes,
                   count(*) FILTER (WHERE d.weekly_hours IS NULL AND d.employed) AS days_without_contract
              FROM d
             GROUP BY {$groupBy} d.period_start, d.period_end
             ORDER BY {$this->orderBy($query->grouping)}
            SQL;
    }

    /**
     * Los parametros del informe, **en el orden en que aparecen en el texto**.
     *
     * PostgreSQL los enlaza por posicion, asi que este orden es el de las
     * clausulas: primero los filtros de `subjects`, despues el calendario y por
     * ultimo el recorte del cubo al rango pedido.
     *
     * @return list<scalar>
     */
    private function rowsBindings(PeriodReportQuery $query): array
    {
        [, $bindings] = $this->subjectFilters($query);

        $range = [$query->range->isoFrom(), $query->range->isoTo()];

        return [
            ...$bindings,
            // `calendar`.
            ...$range,
            // El recorte del cubo, que las cuatro granularidades usan salvo la
            // diaria: ahi el cubo es el propio dia y ya cae dentro del rango.
            ...($query->granularity === ReportGranularity::Day ? [] : $range),
            // Los dos acotes redundantes del `LEFT JOIN` con `daily_totals`.
            ...$range,
            // Y los del contrato, **en el orden invertido**: `valid_from <= to`
            // primero y `valid_to >= from` despues, que es como aparecen en el
            // texto.
            $query->range->isoTo(),
            $query->range->isoFrom(),
        ];
    }

    /**
     * Inicio y fin del cubo, **ya recortados al rango pedido**.
     *
     * Una semana a caballo del 1 de marzo, en un informe que empieza el 1, se
     * devuelve como `2026-03-01 → 2026-03-01`. La fila describe exactamente los
     * dias que ha contado: si dijera «semana del 23 de febrero» con el total de
     * un solo dia, quien la lee compararia siete dias con uno.
     *
     * `date_trunc('week', ...)` empieza en lunes, que es la semana ISO 8601.
     *
     * @return array{start: string, end: string}
     */
    private function bucketExpressions(ReportGranularity $granularity): array
    {
        return match ($granularity) {
            ReportGranularity::Day => [
                'start' => 'c.work_date',
                'end' => 'c.work_date',
            ],
            ReportGranularity::Week => [
                'start' => "GREATEST(date_trunc('week', c.work_date)::date, ?::date)",
                'end' => "LEAST((date_trunc('week', c.work_date) + interval '6 days')::date, ?::date)",
            ],
            ReportGranularity::Month => [
                'start' => "GREATEST(date_trunc('month', c.work_date)::date, ?::date)",
                'end' => "LEAST((date_trunc('month', c.work_date) + interval '1 month' - interval '1 day')::date, ?::date)",
            ],
            // Un solo cubo con el rango entero. Los dos extremos ya son el
            // recorte, asi que se enlazan directos.
            ReportGranularity::Range => [
                'start' => '?::date',
                'end' => '?::date',
            ],
        };
    }

    private function groupKeys(ReportGrouping $grouping): string
    {
        return match ($grouping) {
            ReportGrouping::Employee => 'd.employee_uuid, d.employee_code, d.first_name, d.last_name, '
                .'d.department_id, d.department_name',
            ReportGrouping::Department => 'd.department_id, d.department_name',
            // Un centro por instalacion (ADR-040): la fila es el total sin
            // desglosar y no necesita ninguna clave.
            ReportGrouping::Site => '',
        };
    }

    private function orderBy(ReportGrouping $grouping): string
    {
        return match ($grouping) {
            // Orden estable y previsible: dos personas con el mismo apellido no
            // pueden cambiar de sitio entre dos ejecuciones del mismo informe.
            ReportGrouping::Employee => 'lower(d.last_name), lower(d.first_name), d.employee_uuid, d.period_start',
            // Quien no tiene departamento va al final y no desaparece: sin su
            // fila, la suma de los departamentos no cuadraria con el centro.
            ReportGrouping::Department => 'd.department_name NULLS LAST, d.period_start',
            ReportGrouping::Site => 'd.period_start',
        };
    }

    /**
     * Los predicados sobre `employees`, con sus parametros.
     *
     * Va aparte y no repetido para que el alcance, el departamento y el empleado
     * no puedan diferir entre las filas y la cobertura de contrato: si se
     * escribieran dos veces, bastaria tocar una para que el informe contara una
     * plantilla y el aviso de «dias sin contrato» contara otra.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function subjectFilters(PeriodReportQuery $query): array
    {
        $sql = '';

        /** @var list<scalar> $bindings */
        $bindings = [];

        [$scopeSql, $scopeBindings] = $this->scopePredicate($query->scope);
        $sql .= $scopeSql;
        $bindings = [...$bindings, ...$scopeBindings];

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
     * Cuantos cubos de periodo produce el rango. Se calcula en PHP porque es
     * aritmetica de calendario y no hace falta preguntarselo a la base de datos
     * para decidir si el informe cabe en una respuesta sincrona.
     */
    private function bucketCount(PeriodReportQuery $query): int
    {
        $from = $this->asDate($query->range->isoFrom());
        $to = $this->asDate($query->range->isoTo());

        return match ($query->granularity) {
            ReportGranularity::Day => $query->range->days(),
            ReportGranularity::Range => 1,
            // Semanas ISO tocadas por el rango: se cuentan desde el lunes de la
            // primera, que es como las agrupa `date_trunc`.
            ReportGranularity::Week => (int) floor(
                ((int) $from->modify('monday this week')->diff($to)->format('%a')) / 7,
            ) + 1,
            ReportGranularity::Month => ((int) $to->format('Y') - (int) $from->format('Y')) * 12
                + (int) $to->format('n') - (int) $from->format('n') + 1,
        };
    }

    private function toRow(object $row, ReportGrouping $grouping, string $siteName): PeriodReportRow
    {
        $reader = Row::of($row);

        return new PeriodReportRow(
            subject: $this->toSubject($reader, $grouping, $siteName),
            periodStart: $this->asDate($reader->string('period_start')),
            periodEnd: $this->asDate($reader->string('period_end')),
            workedMinutes: $reader->int('worked_minutes'),
            shiftCount: $reader->int('total_shifts'),
            daysInPeriod: $reader->int('days_in_period'),
            daysWithActivity: $reader->int('days_with_activity'),
            openShiftDays: $reader->int('open_shift_days'),
            incidentDays: $reader->int('incident_days'),
            contractedMinutes: $reader->int('contracted_minutes'),
            daysWithoutContract: $reader->int('days_without_contract'),
        );
    }

    private function toSubject(Row $reader, ReportGrouping $grouping, string $siteName): ReportSubject
    {
        return match ($grouping) {
            ReportGrouping::Employee => ReportSubject::employee(
                uuid: $reader->string('employee_uuid'),
                employeeCode: $reader->string('employee_code'),
                fullName: trim($reader->string('first_name').' '.$reader->string('last_name')),
                departmentId: $reader->nullableInt('department_id'),
                departmentName: $reader->nullableString('department_name'),
            ),
            ReportGrouping::Department => ReportSubject::department(
                $reader->nullableInt('department_id'),
                $reader->nullableString('department_name'),
            ),
            ReportGrouping::Site => ReportSubject::site($siteName),
        };
    }

    /**
     * PostgreSQL devuelve `date` como `YYYY-MM-DD`. Se fija a medianoche UTC por
     * lo mismo que en `DateRange`: son etiquetas de calendario que solo se
     * comparan entre si, no instantes.
     */
    private function asDate(string $isoDate): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($isoDate, 0, 10).' 00:00:00', new DateTimeZone('UTC'));
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
     * @param  list<scalar>  $bindings
     */
    private function scalar(string $sql, array $bindings): int
    {
        $rows = $this->select($sql, $bindings);

        return $rows === [] ? 0 : Row::of($rows[0])->int('count');
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
                throw ReportTooLargeForSynchronousDelivery::timedOut($this->timeoutSeconds);
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
