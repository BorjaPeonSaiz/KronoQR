<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\AdoptionFactsReader;
use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Reporting\Application\Query\AdoptionReportCriteria;
use App\Modules\Reporting\Application\Query\ReadAdoptionReport;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorKey;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReportQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseAdoptionFactsReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Time\WallClockBudget;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El cuadro de impacto sobre dos años de historico, dentro del presupuesto de
 * RNF-P-05** (RF-IN-08, tarea 3.13, paso 4 de `/informe-nuevo`).
 *
 * ## Que se mide y por que con datos de verdad
 *
 * El cuadro devuelve doce numeros por mucho volumen que haya detras, asi que el
 * riesgo no es el tamaño de la respuesta: es **lo que cuesta producirla**. Cruza
 * `shift_entries`, `scan_events`, `shift_corrections`, `incidents` y
 * `error_events` de DOS periodos, y ademas pide dos informes de horas. Con diez
 * filas por tabla, cualquier plan de consulta pasa.
 *
 * Se siembra la plantilla del Anexo A —500 personas— con dos años de jornadas y
 * dos escaneos por jornada, que es el volumen que la ficha fija: unas 365.000
 * jornadas y 730.000 escaneos.
 *
 * ## El presupuesto son cinco segundos, y el plan se mira ademas
 *
 * `/informe-nuevo` paso 5: por debajo de 5 s con volumen real, respuesta directa.
 * La cifra del reloj depende de la maquina y por eso **no es lo unico que se
 * comprueba**: el `EXPLAIN ANALYZE` es lo que sigue valiendo mañana en otro
 * servidor. El reloj se afirma en la suite nocturna de rendimiento,
 * con la mediana de varias pasadas (D4, {@see WallClockBudget}).
 *
 * ## `scan_events` se lee por indice, no entera (DB5)
 *
 * El lector convierte los LIMITES de cada periodo —la medianoche local del primer
 * dia y la del dia siguiente al ultimo— a instantes UTC con la zona del centro, y
 * acota `occurred_at` por ese rango (en `scan_events`, expresado en hora de pared
 * UTC). Asi lo resuelve `scan_events_occurred_at_utc_index` sin meter la zona del
 * centro en el esquema (ADR-017, regla dura 13): el indice es de
 * `occurred_at AT TIME ZONE 'UTC'`, y la zona solo entra en dos constantes por
 * consulta. Por que de expresion y no de la columna sola: para no robarle el plan
 * al fichaje (migracion `2026_10_16_100000`, `ScanLogIndexUsageTest`). Hasta la 2.2.1 el filtro era `(occurred_at AT TIME ZONE zona)::date
 * BETWEEN …`, que convertia cada fila y obligaba a recorrer el historico entero.
 *
 * Dos pruebas lo atan: la del plan (sin `Seq Scan` de `scan_events`, con el
 * indice) sobre el SQL que emite el lector, y la de equivalencia, que compara el
 * filtro nuevo con el antiguo alrededor de la medianoche de los dias de cambio de
 * hora de Madrid y de Santiago de Chile (donde el cambio cae a medianoche).
 */

/*
 * ## Estadisticas reales, y por eso la siembra se confirma
 *
 * Un plan solo dice algo si el planificador conoce el volumen. `ANALYZE` exige
 * ser el dueño de la tabla, que es el rol de migracion: hasta la 2.2.0 esta
 * prueba lo lanzaba con el rol de la aplicacion dentro de la transaccion de
 * `RefreshDatabase`, PostgreSQL respondia con un aviso y planificaba con
 * `reltuples = -1` —una fila estimada por nodo sobre 730 000—. Ahora la
 * siembra se confirma ({@see CommittedDatabase}) y se analiza con
 * {@see QueryPlans::analyze()}, que ademas comprueba que las estadisticas
 * quedaron.
 */

uses(CommittedDatabase::class);

/** La plantilla del Anexo A del doc 02: «virtualizacion para 500 empleados». */
const EMPLEADOS_DEL_CUADRO = 500;

/** Dos años de historico, que es el volumen que la ficha 3.13 fija. */
const DIAS_DEL_CUADRO = 730;

/** Primer dia del historico sembrado. */
const PRIMER_DIA_DEL_CUADRO = '2025-01-01';

/**
 * 500 empleados con dos años de jornadas, escaneos, correcciones e incidencias.
 *
 * **Todo se escribe con `INSERT ... SELECT` sobre `generate_series`** y no con
 * filas construidas en PHP: setecientas mil filas por el segundo camino tardarian
 * minutos y llenarian la memoria del proceso, y lo que aqui se mide es el plan de
 * PostgreSQL, no la velocidad del cliente.
 *
 * @return array{site: int}
 */
function volumenDelCuadro(): array
{
    $site = WorkforceFixtures::site('Hotel con dos años');
    $device = AttendanceFixtures::device($site)['id'];
    $ultimoDia = (new DateTimeImmutable(PRIMER_DIA_DEL_CUADRO, new DateTimeZone('UTC')))
        ->modify('+'.(DIAS_DEL_CUADRO - 1).' days')
        ->format('Y-m-d');

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < EMPLEADOS_DEL_CUADRO; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => null,
            'first_name' => 'Persona',
            'last_name' => 'Numero '.$i,
            'employee_code' => 'A'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
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

    /*
     * Las jornadas, de donde sale «registro completo».
     *
     * Una de cada cincuenta personas se deja el turno abierto el 15 de enero, y
     * **su historico termina ahi**.
     *
     * No es un apaño de la prueba: es lo que RN-01 obliga a que sea. La
     * restriccion de exclusion `shift_entries_no_overlap` trata un tramo sin cerrar
     * como un intervalo **sin final**, asi que un turno abierto el 15 de enero
     * solapa con TODO lo que esa persona fiche despues — que es precisamente la
     * invariante que impide que alguien tenga dos jornadas abiertas a la vez.
     *
     * El caso real que esto reproduce: alguien que olvido fichar la salida y se
     * fue de vacaciones, de baja o de la empresa. Es exactamente el defecto que el
     * indicador de «registro completo» existe para enseñar.
     *
     * Se siembra asi de entrada, en una sola sentencia. Hasta el bloque 7 de la
     * 2.2.1 se sembraba todo cerrado y despues se borraba y se reabria, pero la
     * aplicacion ya no tiene `DELETE` y el motor no deja reabrir un tramo
     * cerrado (ADR-057 §2 y §3).
     */
    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, ?, d::date,
               d + interval '6 hours',
               CASE WHEN o.left_open THEN NULL ELSE d + interval '14 hours' END,
               CASE WHEN o.left_open THEN NULL ELSE 480 END,
               CASE WHEN o.left_open THEN 'open' ELSE 'closed' END,
               'qr_kiosk',
               CASE WHEN o.left_open THEN NULL ELSE 'qr_kiosk' END,
               1, now(), now()
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
         CROSS JOIN LATERAL (SELECT e.id % 50 = 0 AND d::date = ?::date AS left_open) AS o
         WHERE NOT (e.id % 50 = 0 AND d::date > ?::date)
        SQL, [$site, PRIMER_DIA_DEL_CUADRO, $ultimoDia, '2026-01-15', '2026-01-15']);

    // La proyeccion, de donde salen las horas (regla dura 7: no se recalcula).
    DB::statement(<<<'SQL'
        INSERT INTO daily_totals
            (employee_id, work_date, total_minutes, shift_count, first_in_at, last_out_at,
             has_open_shift, has_incident, recalculated_at)
        SELECT e.id, d::date, 480, 1, NULL, NULL, FALSE, FALSE, now()
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
        SQL, [PRIMER_DIA_DEL_CUADRO, $ultimoDia]);

    // Dos escaneos por jornada: entrada y salida. Uno de cada cien es por PIN.
    // EN ORDEN CRONOLOGICO, como llegan de verdad: el orden fisico de la tabla
    // es lo que el planificador mira (`pg_stats.correlation`) para decidir si
    // recorrer un mes por `scan_events_occurred_at_utc_index` le sale a cuenta.
    // Sembrados persona a persona, cada mes estaria repartido por toda la tabla.
    DB::statement(<<<'SQL'
        INSERT INTO scan_events
            (scan_id, device_id, employee_id, occurred_at, recorded_at, origin, intent, result,
             worked_minutes, client_meta)
        SELECT gen_random_uuid(), ?, e.id,
               d + make_interval(hours => h),
               d + make_interval(hours => h),
               CASE WHEN e.id % 100 = 0 THEN 'pin_kiosk' ELSE 'qr_kiosk' END,
               'auto',
               CASE WHEN h = 6 THEN 'clock_in' ELSE 'clock_out' END,
               CASE WHEN h = 6 THEN 0 ELSE 480 END,
               '{}'::jsonb
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
         CROSS JOIN (VALUES (6), (14)) AS t(h)
         ORDER BY d, t.h, e.id
        SQL, [$device, PRIMER_DIA_DEL_CUADRO, $ultimoDia]);

    QueryPlans::analyze('shift_entries', 'scan_events', 'daily_totals', 'employees', 'employment_contracts');

    return ['site' => $site];
}

it('compone el cuadro de dos periodos sobre dos años de historico, con el periodo anterior', function (): void {
    volumenDelCuadro();

    $cuadro = app(ReadAdoptionReport::class)->handle(
        new AdoptionReportCriteria(from: '2026-01-01', to: '2026-01-31'),
        maxRangeDays: DateRange::MAXIMUM_DAYS,
        maxRows: 20000,
    );

    expect($cuadro->indicators)->toHaveCount(12)
        // 98 % de jornadas completas: una de cada cincuenta queda abierta.
        ->and($cuadro->indicator(AdoptionIndicatorKey::WorkDaysCompleteRatio)?->current)->toBeGreaterThan(95.0)
        // Y con periodo anterior, que es la mitad del trabajo: enero se compara
        // contra los 31 dias que terminan el 31 de diciembre.
        ->and($cuadro->indicator(AdoptionIndicatorKey::WorkDaysCompleteRatio)?->previous)->not->toBeNull();
})->group('RF-IN-08');

it('compone el cuadro de dos periodos sobre dos años de historico dentro del presupuesto, en mediana', function (): void {
    volumenDelCuadro();

    /** @var ReadAdoptionReport $caso */
    $caso = app(ReadAdoptionReport::class);

    $muestras = WallClockBudget::samples(static fn (): float => WallClockBudget::seconds(static fn () => $caso->handle(
        new AdoptionReportCriteria(from: '2026-01-01', to: '2026-01-31'),
        maxRangeDays: DateRange::MAXIMUM_DAYS,
        maxRows: 20000,
    )));

    // La cifra medida se deja a mano con `KRONOQR_PRINT_PLAN=1`, igual que en el
    // informe por periodo: es el numero que se pega en el informe de la tarea y
    // el que hay que volver a mirar el dia que alguien toque una consulta.
    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'RNF-P-05: cuadro de impacto de '.EMPLEADOS_DEL_CUADRO.' empleados y dos periodos en '
            .round(WallClockBudget::median($muestras), 3)." s de mediana\n");
    }

    WallClockBudget::expectMedianBelow($muestras, 5.0, 'RNF-P-05');
})->group('RNF-P-05', 'performance');

it('resuelve el recuento de jornadas completas sin recorrer dos años de shift_entries', function (): void {
    /*
     * La comprobacion que sigue valiendo mañana en otra maquina: lo que se mira es
     * el PLAN, no el reloj.
     *
     * Esta consulta SI es sargable —`work_date` es una fecha civil y se compara
     * con un rango de fechas, sin conversion de zona por medio—, asi que entra por
     * indice. **Cual de ellos lo decide el planificador** y la prueba no lo fija:
     * con un centro por instalacion (ADR-040) el `site_id` no discrimina nada, asi
     * que PostgreSQL puede preferir la restriccion de exclusion de RN-01 al indice
     * `(site_id, work_date)` y las dos son respuestas correctas.
     *
     * Lo que si se afirma es que **no recorre la tabla**: un `Seq Scan` aqui
     * significaria que ningun indice sirve ya, y el cuadro se degradaria con el
     * historico justo en la instalacion mas antigua — que es la que mas tiene que
     * renovar la licencia.
     */
    volumenDelCuadro();

    /** @var list<object> $filas */
    $filas = DB::select(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
        SELECT site_id,
               count(*)                                  AS total,
               count(*) FILTER (WHERE open_entries = 0)  AS complete
          FROM (
                SELECT se.site_id, se.employee_id, se.work_date,
                       count(*) FILTER (WHERE se.clocked_out_at IS NULL) AS open_entries
                  FROM shift_entries se
                 WHERE se.work_date BETWEEN ?::date AND ?::date
                   AND se.status NOT IN ('voided', 'superseded')
                 GROUP BY se.site_id, se.employee_id, se.work_date
               ) AS work_days
         GROUP BY site_id
        SQL, ['2026-01-01', '2026-01-31']);

    $plan = implode("\n", array_map(static function (object $fila): string {
        // `EXPLAIN` devuelve una columna llamada literalmente «QUERY PLAN», con
        // espacio: no hay forma de leerla como propiedad, y su tipo es `mixed`
        // hasta que se comprueba. Mismo apaño que en el informe por periodo.
        $linea = get_object_vars($fila)['QUERY PLAN'] ?? '';

        return is_scalar($linea) ? (string) $linea : '';
    }, $filas));

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, $plan."\n");
    }

    expect($plan)->not->toContain('Seq Scan on shift_entries');
})->group('RNF-P-05', 'RF-IN-08');

it('lee scan_events por scan_events_occurred_at_utc_index, una vez por consulta y sin recorrerla entera', function (): void {
    /*
     * DB5. Se captura el SQL que emite el lector de verdad (no se transcribe) y
     * se mira su PLAN, que es lo que sigue valiendo mañana en otra maquina.
     *
     * - Sin `Seq Scan on scan_events`: con el filtro antiguo por fecha civil
     *   convertida fila a fila, cada cuadro leia el historico entero.
     * - Con `scan_events_occurred_at_utc_index`: el que el filtro por instantes
     *   tiene que usar.
     * - Un solo nodo sobre `scan_events`: la regresion de la decision 15 de la
     *   ficha 3.13 —subconsultas correlacionadas por periodo y origen, catorce
     *   lecturas de la tabla— apareceria aqui como varios nodos.
     */
    volumenDelCuadro();

    $consultas = QueryPlans::selectsOn('scan_events', static function (): void {
        resolve(AdoptionFactsReader::class)->factsFor(
            AdoptionReportQuery::of(DateRange::between('2026-01-01', '2026-01-31')),
            timeZone: 'Europe/Madrid',
        );
    });

    expect($consultas)->toHaveCount(1);

    $root = QueryPlans::root('EXPLAIN (ANALYZE, FORMAT JSON) ', $consultas[0]);

    expect($root)->not->toBeNull('El plan de ejecucion no se pudo leer');
    \assert($root !== null);

    $nodos = QueryPlans::flatten($root);

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, json_encode($root, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    $sobreScanEvents = array_filter($nodos, static fn (array $nodo): bool => ($nodo['Relation Name'] ?? '') === 'scan_events');

    expect(QueryPlans::scansSequentially($nodos, 'scan_events'))->toBeFalse('El cuadro recorre scan_events entera')
        ->and(QueryPlans::usesIndex($nodos, 'scan_events_occurred_at_utc_index'))->toBeTrue('El cuadro no usa scan_events_occurred_at_utc_index')
        ->and($sobreScanEvents)->toHaveCount(1);
})->group('RNF-P-05', 'RNF-P-02', 'RF-IN-08');

/**
 * Los dias civiles alrededor de los que se comprueba la equivalencia: los de
 * cambio de hora de 2026 y los de su alrededor.
 *
 * - **Europe/Madrid:** el 29 de marzo tiene 23 horas y el 25 de octubre 25; el
 *   cambio es de madrugada, no a medianoche.
 * - **America/Santiago:** el cambio cae JUSTO a medianoche. El 5 de abril, a las
 *   00:00 (-03) se vuelve a las 23:00 (-04) del dia 4: se repite la ultima hora
 *   del dia 4, no la medianoche. El 6 de septiembre no existe la hora de las
 *   00:00 a las 01:00 (de las 23:59:59 del dia 5 se salta a la 01:00).
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function diasDeCambioDeHoraDelCuadro(): array
{
    return [
        'Madrid' => ['Europe/Madrid', ['2026-03-29', '2026-10-25']],
        'Santiago de Chile' => ['America/Santiago', ['2026-04-05', '2026-09-06']],
    ];
}

it('cuenta los mismos escaneos que el filtro por fecha civil alrededor de la medianoche de los cambios de hora', /** @param list<string> $dias */ function (string $zona, array $dias): void {
    /*
     * La equivalencia que permitio cambiar el filtro (DB5): convertir los
     * LIMITES del periodo a instantes tiene que dar exactamente el mismo
     * conjunto de filas que convertir cada fila a su fecha civil, que es el
     * criterio de RN-05 y de las reglas duras 3 y 4. El filtro antiguo se usa
     * aqui como oraculo.
     *
     * Escaneos cada diez minutos durante tres dias alrededor de cada cambio, mas
     * uno a un segundo y a un microsegundo de cada medianoche local; y se piden
     * periodos de un dia y de varios que empiezan y terminan en los dias del
     * cambio.
     */
    $site = WorkforceFixtures::site('Hotel del cambio de hora');
    $device = AttendanceFixtures::device($site)['id'];
    $employee = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));

    foreach ($dias as $dia) {
        DB::statement(<<<'SQL'
            INSERT INTO scan_events
                (scan_id, device_id, employee_id, occurred_at, recorded_at, origin, intent, result,
                 worked_minutes, client_meta)
            SELECT gen_random_uuid(), ?, ?, t, t, 'qr_kiosk', 'auto', 'clock_in', 0, '{}'::jsonb
              FROM (
                    SELECT generate_series(
                               ((?::date - 1)::timestamp AT TIME ZONE ?),
                               ((?::date + 2)::timestamp AT TIME ZONE ?),
                               interval '10 minutes') AS t
                    UNION ALL
                    SELECT (d::timestamp AT TIME ZONE ?) + o
                      FROM generate_series(?::date - 1, ?::date + 2, interval '1 day') AS d
                     CROSS JOIN (VALUES (interval '-1 second'), (interval '-1 microsecond'),
                                        (interval '1 microsecond'), (interval '1 second')) AS v(o)
                   ) AS instantes
            SQL, [$device, $employee, $dia, $zona, $dia, $zona, $zona, $dia, $dia]);
    }

    $lector = resolve(AdoptionFactsReader::class);
    $comprobados = 0;

    foreach ($dias as $dia) {
        $base = new DateTimeImmutable($dia, new DateTimeZone('UTC'));
        $periodos = [
            [$base->modify('-1 day'), $base->modify('-1 day')],
            [$base, $base],
            [$base->modify('+1 day'), $base->modify('+1 day')],
            [$base->modify('-1 day'), $base],
            [$base, $base->modify('+1 day')],
        ];

        foreach ($periodos as [$desde, $hasta]) {
            $consulta = AdoptionReportQuery::of(DateRange::between($desde->format('Y-m-d'), $hasta->format('Y-m-d')));
            $hechos = $lector->factsFor($consulta, $zona);

            foreach (['current' => [$consulta->range, $hechos->current], 'previous' => [$consulta->previousRange, $hechos->previous]] as $cual => [$rango, $periodo]) {
                $oraculo = DB::table('scan_events')
                    ->whereRaw('(occurred_at AT TIME ZONE ?)::date BETWEEN ?::date AND ?::date', [$zona, $rango->isoFrom(), $rango->isoTo()])
                    ->count();

                expect($periodo->attendedScans)->toBe($oraculo, $zona.' '.$cual.' '.$rango->isoFrom().'..'.$rango->isoTo());
                $comprobados += $oraculo;
            }
        }
    }

    // El control de que la prueba mira algo: con cero filas, la igualdad pasaria siempre.
    expect($comprobados)->toBeGreaterThan(0);
})->with(diasDeCambioDeHoraDelCuadro())->group('RNF-P-05', 'RF-IN-08', 'RN-09');

it('traduce la cancelacion de PostgreSQL al 422 del cuadro y no a un 500', function (): void {
    /*
     * EL GEMELO DE `ComplianceFactsReaderVolumeTest` PARA ADOPCION (RF-IN-08).
     *
     * El `SQLSTATE 57014` (`query_canceled`) no es una averia: es el
     * `statement_timeout` haciendo su trabajo sobre un rango demasiado grande. Un
     * `500` mandaria a soporte a buscar un fallo que no existe; quien recibe el
     * `422` tiene algo que cambiar —acortar el rango— y el `detail` se lo dice.
     *
     * Hasta el cierre de la Fase 3 este camino no lo recorria ninguna prueba, y
     * ademas leia el `SQLSTATE` de `getCode()` en vez de `errorInfo[0]`, que es
     * como lo leen sus dos hermanas: `QueryException::getCode()` hereda el codigo
     * de la `PDOException` envuelta, que segun el driver es la cadena del
     * `SQLSTATE` o un entero. Con `getCode()`, una cancelacion podia escaparse
     * como `500`.
     *
     * Se provoca con una conexion que cancela a proposito, en lugar de con un
     * volumen que tarde: asi la prueba dice lo mismo en cualquier maquina.
     */
    $cancelada = new PDOException('SQLSTATE[57014]: Query canceled');
    $cancelada->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];

    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')->andThrow(
        new QueryException('pgsql', 'SELECT 1', [], $cancelada),
    );

    /** @var WorkDayCompletionReader&MockInterface $workDays */
    $workDays = Mockery::mock(WorkDayCompletionReader::class);

    $reader = new DatabaseAdoptionFactsReader($connection, $workDays, 10);

    expect(fn (): AdoptionFacts => $reader->factsFor(
        AdoptionReportQuery::of(DateRange::between('2026-01-01', '2026-01-31')),
        timeZone: 'Europe/Madrid',
    ))->toThrow(
        ReportTooLargeForSynchronousDelivery::class,
        'El cuadro de impacto ha superado los 10 segundos y se ha cancelado. Reduce el rango.',
    );
})->group('RF-IN-08', 'RNF-P-05');

it('pone el techo de tiempo EN SEGUNDOS y dentro de la transaccion', function (): void {
    /*
     * `= '10s'` y no `= 10000`. Las dos formas valen para PostgreSQL —sin unidad
     * el valor son milisegundos—, pero las otras dos lecturas de informes del
     * repositorio escriben la unidad, y una cifra desnuda de cinco digitos es
     * justo la que alguien lee como segundos al ajustar `config/reporting.php`.
     * La sentencia se afirma literal para que la divergencia vuelva a notarse.
     */
    // Sin volumen: lo que se afirma es la SENTENCIA, no el tiempo.
    WorkforceFixtures::site();

    $sentencias = [];

    DB::listen(static function (object $query) use (&$sentencias): void {
        /** @var object{sql: string} $query */
        $sentencias[] = $query->sql;
    });

    app(AdoptionFactsReader::class)->factsFor(
        AdoptionReportQuery::of(DateRange::between('2026-01-05', '2026-01-11')),
        timeZone: 'Europe/Madrid',
    );

    $puestas = array_values(array_filter(
        $sentencias,
        static fn (string $sql): bool => str_contains($sql, 'SET LOCAL statement_timeout'),
    ));

    expect($puestas)->toHaveCount(1)
        ->and($puestas[0])->toBe("SET LOCAL statement_timeout = '10s'");
})->group('RF-IN-08', 'RNF-P-05');
