<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Port\UseCaseMetrics;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;
use Tests\Support\Time\WallClockBudget;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El informe por periodo con volumen realista (**RNF-P-05**, `/informe-nuevo`
 * pasos 4 y 5, tarea 2.8).
 *
 * ## Lo que se mide y por que hacen falta datos de verdad
 *
 * RNF-P-05 fija el presupuesto: **el informe mensual de 500 empleados por debajo
 * de 5 s de forma sincrona**. Con diez filas en la tabla, cualquier plan de
 * ejecucion parece bueno: un *sequential scan* sobre cien filas es instantaneo y
 * sobre cuatrocientas mil no. Por eso esta prueba siembra 500 empleados x 730
 * dias —365.000 filas de `daily_totals`, el volumen que fija el plan de la
 * tarea— y comprueba dos cosas distintas:
 *
 *   1. **El plan**: `EXPLAIN ANALYZE` de la consulta mensual **sin *sequential
 *      scan* sobre `daily_totals`**. Es la comprobacion que sigue valiendo
 *      manana en una maquina mas rapida; el reloj no.
 *   2. **El tiempo**: por debajo del presupuesto. Es la que traduce el plan a lo
 *      que nota quien cierra una nomina. Se afirma solo sin instrumentacion de
 *      cobertura ({@see WallClockBudget}); bajo Xdebug se anuncia y no se afirma.
 *
 * ## Las filas de `daily_totals` se escriben directamente
 *
 * Es la unica prueba de esta tarea que lo hace, y esta justificado: generar
 * 365.000 jornadas por el agregado —un `INSERT` en `shift_entries`, un evento y
 * un recalculo cada una— tardaria horas y lo que aqui se mide es **el plan de
 * PostgreSQL**, no la coherencia de la proyeccion. Que la proyeccion y el informe
 * coinciden lo comprueban las pruebas de feature, que si pasan por el agregado.
 *
 * ## Es lenta a proposito
 *
 * Sembrar el volumen cuesta unos segundos. Va en la suite de integracion, que es
 * la que corre contra PostgreSQL de verdad y la que puede permitirselo; la suite
 * unitaria tiene presupuesto de duracion y esto no cabria en el.
 */

// En la sesion del rol dueño de las tablas y dentro de una transaccion que se
// deshace: es lo que deja a `ANALYZE` ver lo sembrado sin que sus estadisticas
// sobrevivan a la prueba (ver RefreshDatabaseAsOwner, que cuenta el fallo de
// la CI de la 19a que lo motivo).
uses(RefreshDatabaseAsOwner::class);

/** La plantilla del Anexo A del doc 02: «virtualizacion para 500 empleados». */
const EMPLEADOS = 500;

/**
 * Dos años de historico, que es el volumen que el plan de la tarea fija para el
 * `EXPLAIN ANALYZE`: 500 × 730 ≈ 365.000 filas en `daily_totals`.
 *
 * **No son los 90 dias del `VolumeSeeder`, y la diferencia importa.** Con 90
 * dias, un mes es el 34 % de la tabla y un *sequential scan* es el plan
 * **correcto**: la prueba fallaria acusando de lento a PostgreSQL por hacer lo
 * que hay que hacer. El indice se justifica cuando el historico crece, que es lo
 * que pasa en una instalacion real con cuatro años de retencion (RL-02).
 */
const DIAS = 730;

/** Primer dia del historico sembrado. */
const PRIMER_DIA = '2025-01-01';

/**
 * 500 empleados en 10 departamentos, con dos años de jornadas cada uno.
 *
 * **El historico se escribe con una sola sentencia** —`INSERT ... SELECT` sobre
 * `generate_series`— y no con 365.000 filas construidas en PHP: aquello tardaria
 * minutos y llenaria la memoria del proceso, y lo que aqui se mide es el plan de
 * PostgreSQL, no la velocidad del cliente.
 *
 * Por omision, los dos años del `EXPLAIN`; las pruebas que solo agregan enero
 * de 2026 piden menos (ver {@see informeMensualDelVolumen()}).
 *
 * @return array{site: int, from: string, to: string}
 */
function volumenDeInforme(string $primerDia = PRIMER_DIA, int $dias = DIAS): array
{
    $site = WorkforceFixtures::site('Hotel con volumen');

    $departamentos = [];

    for ($i = 0; $i < 10; $i++) {
        $departamentos[] = WorkforceFixtures::department($site, 'Area '.$i);
    }

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < EMPLEADOS; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => $departamentos[$i % 10],
            'first_name' => 'Persona',
            'last_name' => 'Numero '.$i,
            'employee_code' => 'V'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            'email' => null,
            'status' => 'active',
            'hired_at' => '2024-01-01',
            'terminated_at' => null,
            'locale' => 'es',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    foreach (array_chunk($empleados, 500) as $lote) {
        DB::table('employees')->insert($lote);
    }

    DB::statement(<<<'SQL'
        INSERT INTO employment_contracts
            (employee_id, weekly_hours, annual_hours, schedule_type, valid_from, valid_to, created_at, created_by_user_id)
        SELECT e.id, 40, NULL, 'turnos', DATE '2024-01-01', NULL, now(), NULL
          FROM employees e
        SQL);

    DB::statement(<<<'SQL'
        INSERT INTO daily_totals
            (employee_id, work_date, total_minutes, shift_count, first_in_at, last_out_at,
             has_open_shift, has_incident, recalculated_at)
        SELECT e.id, d::date, 480, 1, NULL, NULL, FALSE, FALSE, now()
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
        SQL, [$primerDia, (new DateTimeImmutable($primerDia, new DateTimeZone('UTC')))
        ->modify('+'.($dias - 1).' days')
        ->format('Y-m-d')]);

    // Sin esto, el planificador trabaja con las estadisticas de una tabla vacia
    // y elige un plan que no tiene nada que ver con el de produccion: la prueba
    // mediria el plan equivocado y pasaria o fallaria por casualidad.
    //
    // Con el rol de migracion, que es el dueño, y en la misma sesion que sembro
    // (RefreshDatabaseAsOwner). {@see QueryPlans::analyze()} falla si no
    // deja estadisticas, que es lo que pasaba en silencio con el de la aplicacion.
    QueryPlans::analyze('daily_totals', 'employees', 'employment_contracts', 'departments');

    return [
        'site' => $site,
        'from' => '2026-01-01',
        'to' => '2026-01-31',
    ];
}

/**
 * `EXPLAIN (ANALYZE, BUFFERS)` de una sentencia y el estado de las tablas del
 * informe, como texto para la salida de la prueba: con el reloj solo se sabe
 * que tardo; con esto, por que.
 *
 * @param  array<int, mixed>  $bindings
 */
function periodReportVolumeDiagnosis(string $sql, array $bindings): string
{
    $plan = array_map(static function (object $fila): string {
        $linea = get_object_vars($fila)['QUERY PLAN'] ?? '';

        return is_scalar($linea) ? (string) $linea : '';
    }, DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $bindings));

    $tablas = array_map(static fn (object $fila): string => implode(' ', array_map(
        static fn (mixed $valor): string => is_scalar($valor) ? (string) $valor : 'null',
        get_object_vars($fila),
    )), DB::select(<<<'SQL'
        SELECT s.relname,
               'live=' || s.n_live_tup AS live, 'dead=' || s.n_dead_tup AS dead,
               'reltuples=' || c.reltuples AS reltuples, 'relpages=' || c.relpages AS relpages,
               'analyze=' || COALESCE(s.last_analyze::text, '-') AS analyzed,
               'autoanalyze=' || COALESCE(s.last_autoanalyze::text, '-') AS autoanalyzed,
               'autovacuum=' || COALESCE(s.last_autovacuum::text, '-') AS autovacuumed
          FROM pg_stat_user_tables s
          JOIN pg_class c ON c.oid = s.relid
         WHERE s.relname IN ('daily_totals', 'employees', 'employment_contracts', 'absences', 'departments', 'shift_entries')
         ORDER BY s.relname
        SQL));

    return "RNF-P-05 plan:\n  ".implode("\n  ", $plan)."\nRNF-P-05 tablas:\n  ".implode("\n  ", $tablas)."\n";
}

it('genera el informe mensual de 500 empleados dentro del presupuesto de RNF-P-05', function (): void {
    $volumen = volumenDeInforme();

    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    $consulta = new PeriodReportQuery(
        scope: AccessScope::unrestricted(),
        range: DateRange::between($volumen['from'], $volumen['to']),
        granularity: ReportGranularity::Month,
        grouping: ReportGrouping::Employee,
        departmentId: null,
        employeeUuid: null,
    );

    // El desglose de lo medido, para que un fallo diga DONDE se fue el tiempo.
    // En local el caso de uso entero cuesta ~0,07 s, y en la CI de la 19a cayo
    // tres veces seguidas con 5,7-5,8 s y el mismo codigo: o espera
    // PostgreSQL, o espera Redis (la metrica del caso de uso se escribe dentro
    // de lo cronometrado), o es PHP. Escuchar no cambia lo medido: son quince
    // sentencias y una observacion.
    /** @var list<array{sql: string, bindings: array<int, mixed>, ms: float}> $sentencias */
    $sentencias = [];
    DB::listen(static function (QueryExecuted $sentencia) use (&$sentencias): void {
        $sentencias[] = ['sql' => $sentencia->sql, 'bindings' => array_values($sentencia->bindings), 'ms' => $sentencia->time ?? 0.0];
    });

    $metrica = 0.0;
    $anotar = static function (float $segundos) use (&$metrica): void {
        $metrica += $segundos;
    };
    app()->extend(UseCaseMetrics::class, static fn (UseCaseMetrics $real): UseCaseMetrics => new readonly class($real, $anotar) implements UseCaseMetrics
    {
        /** @param  Closure(float): void  $anotar */
        public function __construct(private UseCaseMetrics $real, private Closure $anotar) {}

        public function observe(string $useCase, string $outcome, float $seconds): void
        {
            $empezo = microtime(true);
            $this->real->observe($useCase, $outcome, $seconds);
            ($this->anotar)(microtime(true) - $empezo);
        }
    });

    $empezo = microtime(true);
    $informe = $caso->handle($consulta, maxRangeDays: 92, maxRows: 20000);
    $tardo = microtime(true) - $empezo;

    // La cifra medida se deja a mano con `KRONOQR_PRINT_PLAN=1`, igual que el
    // plan: es el numero que se pega en el informe de la tarea y el que hay que
    // volver a mirar el dia que alguien toque la consulta. Por encima del
    // presupuesto sale siempre, con el desglose.
    if (getenv('KRONOQR_PRINT_PLAN') !== false || $tardo >= 5.0) {
        $sql = array_sum(array_column($sentencias, 'ms')) / 1000;
        usort($sentencias, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $lenta = $sentencias[0] ?? ['sql' => '-', 'bindings' => [], 'ms' => 0.0];

        fwrite(STDOUT, 'RNF-P-05: informe mensual de '.EMPLEADOS.' empleados en '
            .round($tardo, 3).' s (SQL '.round($sql, 3).' s en '.\count($sentencias).' sentencias, la mas lenta '
            .round($lenta['ms'], 1).' ms «'.mb_substr((string) preg_replace('/\s+/', ' ', $lenta['sql']), 0, 60).'»; '
            .'metrica en Redis '.round($metrica, 3).' s; resto '
            .round($tardo - $sql - $metrica, 3)." s)\n");

        // Por encima del presupuesto, ademas, el plan de la sentencia mas lenta
        // y el estado de las tablas que lee (y con `KRONOQR_PRINT_PLAN=1`).
        if ($lenta['sql'] !== '-') {
            fwrite(STDOUT, periodReportVolumeDiagnosis($lenta['sql'], $lenta['bindings']));
        }
    }

    expect($informe->rowCount())->toBe(EMPLEADOS)
        // 31 dias de enero x 480 minutos por persona.
        ->and($informe->rows[0]->workedMinutes)->toBe(31 * 480);

    // El reloj solo se afirma sin instrumentacion: bajo `make coverage` Xdebug
    // mide su propio coste (5,88 s el 02-10-2026, CI-COB-01) y la prueba se queda
    // con el resultado de arriba y con el plan de la de abajo.
    WallClockBudget::expectBelow($tardo, 5.0, 'RNF-P-05');
})->group('RNF-P-05', 'RF-IN-01');

it('resuelve la consulta mensual sin recorrer daily_totals entera', function (): void {
    // La comprobacion que sigue valiendo manana en otra maquina: lo que se mira
    // es el PLAN, no el reloj. Si aparece un `Seq Scan on daily_totals`, falta un
    // indice y el informe se degrada con el historico —cuatro anos de retencion
    // (RL-02) son cerca de un millon de filas—.
    $volumen = volumenDeInforme();

    /** @var list<object> $filas */
    $filas = DB::select(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
        WITH subjects AS (
            SELECT e.id AS employee_id, e.uuid, e.hired_at::date AS hired_on, e.terminated_at::date AS terminated_on
              FROM employees e
        ), calendar AS (
            SELECT generate_series(?::date, ?::date, interval '1 day')::date AS work_date
        ), grid AS (
            SELECT s.*, c.work_date FROM subjects s CROSS JOIN calendar c
        )
        SELECT g.uuid,
               count(*) AS days_in_period,
               COALESCE(sum(dt.total_minutes), 0) AS worked_minutes,
               ROUND(COALESCE(sum(ec.weekly_hours), 0) * 60.0 / 7) AS contracted_minutes
          FROM grid g
          LEFT JOIN daily_totals dt
                 ON dt.employee_id = g.employee_id
                AND dt.work_date = g.work_date
                AND dt.work_date BETWEEN ?::date AND ?::date
          LEFT JOIN employment_contracts ec
                 ON ec.employee_id = g.employee_id
                AND g.work_date >= ec.valid_from
                AND (ec.valid_to IS NULL OR g.work_date <= ec.valid_to)
                AND ec.valid_from <= ?::date
                AND (ec.valid_to IS NULL OR ec.valid_to >= ?::date)
         GROUP BY g.uuid
        SQL, [
        $volumen['from'],
        $volumen['to'],
        $volumen['from'],
        $volumen['to'],
        $volumen['to'],
        $volumen['from'],
    ]);

    $plan = implode("\n", array_map(static function (object $fila): string {
        // `EXPLAIN` devuelve una columna llamada literalmente «QUERY PLAN», con
        // espacio: no hay forma de leerla como propiedad, y su tipo es `mixed`
        // hasta que se comprueba.
        $linea = get_object_vars($fila)['QUERY PLAN'] ?? '';

        return is_scalar($linea) ? (string) $linea : '';
    }, $filas));

    // El plan completo se pega en el informe de la tarea; aqui se deja
    // disponible —**antes** de la asercion— por si la prueba falla en otra
    // maquina y hay que leerlo para saber por que.
    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, $plan."\n");
    }

    expect($plan)->not->toContain('Seq Scan on daily_totals');
})->group('RNF-P-05');

/*
 * Las dos agregaciones que el informe hace en PostgreSQL y no en el dominio, con
 * el mismo volumen (doc 02 §9.5, «informe o exportacion: integracion con
 * volumen»; T2 de la verificacion de la 2.1.0). Sin estas dos, RF-IN-02 y
 * RF-IN-03 solo se comprobaban con una docena de filas en las pruebas de
 * feature, donde un `GROUP BY` mal escrito y uno bien escrito dan lo mismo.
 *
 * No miden tiempo, asi que no siembran los dos años del `EXPLAIN`: les basta
 * enero de 2026 con el mes de antes y el de despues, 500 x 90 = 45.000 filas.
 * Los meses vecinos no sobran: son los que hacen fallar un filtro de fechas
 * que se saliera del rango y sumara diciembre o febrero.
 */

/** Primer dia del volumen de las agregaciones: diciembre de 2025. */
const PERIOD_REPORT_VOLUME_PRIMER_DIA_AGREGACION = '2025-12-01';

/** Diciembre, enero y febrero de 2026: 31 + 31 + 28. */
const PERIOD_REPORT_VOLUME_DIAS_AGREGACION = 90;

/**
 * La consulta mensual de enero sobre el volumen sembrado.
 *
 * @param  array{site: int, from: string, to: string}  $volumen
 */
function informeMensualDelVolumen(array $volumen, ReportGrouping $agrupacion): PeriodReportQuery
{
    return new PeriodReportQuery(
        scope: AccessScope::unrestricted(),
        range: DateRange::between($volumen['from'], $volumen['to']),
        granularity: ReportGranularity::Month,
        grouping: $agrupacion,
        departmentId: null,
        employeeUuid: null,
    );
}

it('agrega por departamento el mes de 500 empleados', function (): void {
    $volumen = volumenDeInforme(PERIOD_REPORT_VOLUME_PRIMER_DIA_AGREGACION, PERIOD_REPORT_VOLUME_DIAS_AGREGACION);

    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    $informe = $caso->handle(informeMensualDelVolumen($volumen, ReportGrouping::Department), maxRangeDays: 92, maxRows: 20000);

    // Diez departamentos de 50 personas: 50 x 31 dias x 480 minutos cada uno.
    expect($informe->rowCount())->toBe(10)
        ->and(array_unique(array_map(static fn ($fila): int => $fila->workedMinutes, $informe->rows)))->toBe([744000])
        ->and(array_unique(array_map(static fn ($fila): int => $fila->daysWithActivity, $informe->rows)))->toBe([1550]);
})->group('RF-IN-02');

it('compara lo trabajado con lo contratado en el mes de 500 empleados', function (): void {
    $volumen = volumenDeInforme(PERIOD_REPORT_VOLUME_PRIMER_DIA_AGREGACION, PERIOD_REPORT_VOLUME_DIAS_AGREGACION);

    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    $informe = $caso->handle(informeMensualDelVolumen($volumen, ReportGrouping::Employee), maxRangeDays: 92, maxRows: 20000);
    $fila = $informe->rows[0];

    // 40 h semanales prorrateadas a 31 dias: 40 x 31 x 60 / 7 = 10.628,57,
    // redondeado a 10.629 minutos. Se trabajaron 14.880 (31 x 480).
    expect($fila->contractedMinutes)->toBe(10629)
        ->and($fila->deviationMinutes())->toBe(4251)
        ->and($fila->overtimeMinutes())->toBe(4251)
        ->and($fila->daysWithoutContract)->toBe(0);
})->group('RF-IN-03');
