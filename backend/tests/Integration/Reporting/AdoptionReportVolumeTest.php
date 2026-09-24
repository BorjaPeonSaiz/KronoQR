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
use Tests\Support\Database\RefreshDatabase;
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
 * servidor.
 *
 * ## Las cifras medidas, y por que la segunda vuelta las mejoro tres veces
 *
 * Medido en la maquina de desarrollo con este mismo conjunto —500 personas, 365 000
 * jornadas y 730 000 escaneos—:
 *
 * | | Antes (decisiones 1-12) | Ahora (decision 15) |
 * |---|---|---|
 * | Cuadro completo, dos periodos | **2,179 s** | **0,891 s** |
 * | Recorridos de `scan_events` | 14 (`loops=8` + `loops=6`) | **1** (`loops=1`) |
 * | Consulta de fichajes y origenes | — | 627 ms, una pasada |
 * | Recuento de jornadas completas | 136 ms, por indice | 142 ms, por indice |
 *
 * Lo que cambio no fue una optimizacion cosmetica: las subconsultas escalares
 * correlacionadas con la CTE de periodos recorrian `scan_events` **catorce veces**, y
 * como el filtro por fecha civil no es sargable (ver abajo) cada pasada es un
 * recorrido completo. Con los cuatro años de retencion de RL-02 —unas 2,9 M de
 * filas— eran del orden de trece segundos, por encima del `statement_timeout` de
 * diez: el cuadro habria dejado de funcionar **justo en la instalacion mas antigua**,
 * que es la que renueva. Con `count(*) FILTER` y `LEFT JOIN` queda una pasada por
 * consulta, y la prueba del `loops=1` impide que la regresion vuelva sin que nadie la
 * vea.
 *
 * ## Por que el cuadro puede permitirse recorrer `scan_events`
 *
 * Y esto es una decision, no un descuido. El filtro de los escaneos es
 * `(occurred_at AT TIME ZONE 'Europe/Madrid')::date BETWEEN …`, que **no es
 * sargable**: ningun indice de `occurred_at` lo resuelve, porque la expresion
 * indexada tendria que ser la conversion entera —y esa depende de la zona del
 * centro, que es configuracion (ADR-040)—. Un indice sobre
 * `((occurred_at AT TIME ZONE 'Europe/Madrid')::date)` seria un indice con un
 * literal de cliente dentro, que es exactamente lo que ADR-017 y la regla dura 13
 * prohiben.
 *
 * La alternativa —filtrar en UTC y perder la ultima noche del mes— no es una
 * alternativa: rompe RN-05. Asi que el cuadro asume el recorrido, lo acota con su
 * `statement_timeout` y lo mide aqui. Si algun dia no cupiera, la salida no es un
 * indice con la zona dentro sino una columna generada con la zona del centro, y
 * eso es una migracion de expansion que hoy no hace falta.
 */

uses(RefreshDatabase::class);

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

    // Las jornadas, de donde sale «registro completo». Todas cerradas de entrada:
    // los turnos abiertos se marcan despues, y ese orden NO es un capricho.
    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, ?, d::date,
               d + interval '6 hours',
               d + interval '14 hours',
               480, 'closed', 'qr_kiosk', 'qr_kiosk', 1, now(), now()
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
        SQL, [$site, PRIMER_DIA_DEL_CUADRO, $ultimoDia]);

    /*
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
     */
    DB::statement('DELETE FROM shift_entries WHERE work_date > ?::date AND employee_id % 50 = 0', ['2026-01-15']);
    DB::statement(<<<'SQL'
        UPDATE shift_entries
           SET clocked_out_at = NULL, duration_minutes = NULL, status = 'open', clock_out_source = NULL
         WHERE work_date = ?::date
           AND employee_id % 50 = 0
        SQL, ['2026-01-15']);

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
        SQL, [$device, PRIMER_DIA_DEL_CUADRO, $ultimoDia]);

    DB::statement('ANALYZE shift_entries');
    DB::statement('ANALYZE scan_events');
    DB::statement('ANALYZE daily_totals');
    DB::statement('ANALYZE employees');
    DB::statement('ANALYZE employment_contracts');

    return ['site' => $site];
}

it('compone el cuadro de dos periodos sobre dos años de historico dentro del presupuesto', function (): void {
    volumenDelCuadro();

    /** @var ReadAdoptionReport $caso */
    $caso = app(ReadAdoptionReport::class);

    $empezo = microtime(true);
    $cuadro = $caso->handle(
        new AdoptionReportCriteria(from: '2026-01-01', to: '2026-01-31'),
        maxRangeDays: DateRange::MAXIMUM_DAYS,
        maxRows: 20000,
    );
    $tardo = microtime(true) - $empezo;

    // La cifra medida se deja a mano con `KRONOQR_PRINT_PLAN=1`, igual que en el
    // informe por periodo: es el numero que se pega en el informe de la tarea y
    // el que hay que volver a mirar el dia que alguien toque una consulta.
    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'RNF-P-05: cuadro de impacto de '.EMPLEADOS_DEL_CUADRO.' empleados y dos periodos en '
            .round($tardo, 3)." s\n");
    }

    expect($cuadro->indicators)->toHaveCount(12)
        // 98 % de jornadas completas: una de cada cincuenta queda abierta.
        ->and($cuadro->indicator(AdoptionIndicatorKey::WorkDaysCompleteRatio)?->current)->toBeGreaterThan(95.0)
        // Y con periodo anterior, que es la mitad del trabajo: enero se compara
        // contra los 31 dias que terminan el 31 de diciembre.
        ->and($cuadro->indicator(AdoptionIndicatorKey::WorkDaysCompleteRatio)?->previous)->not->toBeNull()
        ->and($tardo)->toBeLessThan(5.0);
})->group('RNF-P-05', 'RF-IN-08');

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

it('recorre scan_events UNA sola vez por consulta y no una por periodo y origen', function (): void {
    /*
     * LA REGRESION QUE ESTA PRUEBA IMPIDE (decision 15 de la ficha 3.13).
     *
     * La primera version del lector preguntaba con subconsultas escalares
     * correlacionadas con la CTE de periodos: tres recuentos y cuatro origenes, todo
     * por dos periodos. `EXPLAIN ANALYZE` lo delataba con `loops=8` sobre
     * `scan_events`: **catorce recorridos completos** de la tabla por peticion.
     *
     * Con 39 000 filas eran 12,6 ms por pasada y no se notaba. El problema es que el
     * filtro por fecha civil NO es sargable —la expresion lleva la zona del centro
     * dentro y no se puede indexar sin meter un literal de cliente en el esquema
     * (ADR-017)—, asi que cada pasada es un recorrido entero: con los cuatro años de
     * retencion de RL-02, unas 2,9 M de filas, catorce pasadas son del orden de trece
     * segundos y el `statement_timeout` de diez corta la consulta. El cuadro habria
     * dejado de funcionar **justo en la instalacion mas antigua**, que es la que
     * renueva.
     *
     * Lo que se afirma es `loops=1`: una pasada, con los dos periodos y los cuatro
     * origenes resueltos por `count(*) FILTER` sobre la misma fila. Es una propiedad
     * del PLAN y no del reloj, asi que sigue valiendo mañana en otra maquina.
     */
    volumenDelCuadro();

    /** @var list<object> $filas */
    $filas = DB::select(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
        WITH periods (period, from_date, to_date) AS (
            VALUES ('current'::text, ?::date, ?::date), ('previous'::text, ?::date, ?::date)
        )
        SELECT p.period,
               count(s.id)                                                           AS attended,
               count(s.id) FILTER (WHERE s.recorded_at - s.occurred_at > ?::interval) AS offline_resolved,
               count(s.id) FILTER (WHERE NOT starts_with(s.result, 'rejected_') AND s.origin = ?) AS origin_0,
               count(s.id) FILTER (WHERE NOT starts_with(s.result, 'rejected_') AND s.origin = ?) AS origin_1,
               count(s.id) FILTER (WHERE NOT starts_with(s.result, 'rejected_') AND s.origin = ?) AS origin_2,
               count(s.id) FILTER (WHERE NOT starts_with(s.result, 'rejected_') AND s.origin = ?) AS origin_3
          FROM periods p
          LEFT JOIN scan_events s
                 ON (s.occurred_at AT TIME ZONE ?)::date BETWEEN p.from_date AND p.to_date
         GROUP BY p.period
        SQL, [
        '2026-01-01', '2026-01-31', '2025-12-01', '2025-12-31',
        '60 seconds',
        'qr_kiosk', 'pin_kiosk', 'manual_admin', 'import',
        'Europe/Madrid',
    ]);

    $plan = implode("\n", array_map(static function (object $fila): string {
        $linea = get_object_vars($fila)['QUERY PLAN'] ?? '';

        return is_scalar($linea) ? (string) $linea : '';
    }, $filas));

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, $plan."\n");
    }

    // Ni un `loops=` por encima de uno sobre `scan_events`: si vuelven las
    // subconsultas correlacionadas, aqui aparece `loops=8` y esto se pone rojo.
    expect($plan)->toContain('on scan_events')
        ->and($plan)->not->toMatch('/on scan_events s.*loops=(?!1\))/');
})->group('RNF-P-05', 'RF-IN-08');

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
