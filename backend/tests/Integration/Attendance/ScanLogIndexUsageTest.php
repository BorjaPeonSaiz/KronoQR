<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\OutOfOrderScans;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El PLAN de las dos consultas calientes del fichaje, contra PostgreSQL de
 * verdad y con volumen (RNF-P-02, tarea 3.6).
 *
 * POR QUE ESTO ES UNA PRUEBA Y NO UN NUMERO DE LA PRUEBA DE CARGA. Cada escaneo
 * pregunta dos veces por el historico de la misma persona: cual fue su ultimo
 * escaneo aceptado (RF-AT-12, ADR-024) y si hay alguno dentro de la ventana
 * anti-rebote (RF-AT-06). Con cuatro años de retencion (RL-02) eso son miles de
 * filas por empleado, y las dos tienen que resolverse leyendo UNA fila del
 * indice `(employee_id, occurred_at DESC)`. El dia que alguien anada un
 * `ORDER BY`, un `OR` o un `JOIN` que impida usarlo, el fichaje seguira siendo
 * correcto y seguira pasando todas las demas pruebas: lo unico que cambiara es
 * que el cambio de turno de las 06:00 dejara de caber en 150 ms. Ese cambio se
 * detecta aqui o no se detecta hasta que el cliente llama.
 *
 * EL SQL NO SE TRANSCRIBE, SE CAPTURA. Se escucha la conexion, se llama al
 * PUERTO real y se hace `EXPLAIN` sobre lo que emitio el adaptador. Un SQL
 * copiado a mano se queda viejo en cuanto alguien toca el adaptador y a partir
 * de ahi esta prueba certifica un plan que el producto ya no ejecuta — que es
 * peor que no tenerla. La captura y el recorrido del plan viven en
 * `Tests\Support\Database\QueryPlans`, que comparten las pruebas de indices del
 * diario de jornadas, la bandeja de incidencias y la exportacion legal.
 *
 * VOLUMEN DELIBERADO: veinte mil filas. Sobre una tabla de veinte, PostgreSQL
 * elige un recorrido completo porque es mas barato, y la prueba fallaria sin
 * que hubiera nada roto. Con veinte mil, el recorrido deja de ser una opcion
 * razonable y el plan pasa a decir algo.
 *
 * ESTADISTICAS DE VERDAD, Y POR ESO SE CONFIRMA. El volumen solo dice algo si el
 * planificador lo conoce, y para eso hace falta `ANALYZE` con el rol dueño de
 * las tablas ({@see QueryPlans::analyze()}). Hasta la 2.2.0 esta prueba lo
 * lanzaba con el rol de la aplicacion dentro de la transaccion de
 * `RefreshDatabase`: PostgreSQL respondia con un aviso, no analizaba nada y el
 * plan se decidia con `reltuples = -1`, es decir, con estimaciones de una fila
 * por nodo. Con {@see CommittedDatabase} la siembra se confirma y la conexion
 * del rol de migracion la ve.
 */

uses(CommittedDatabase::class);

/** Cuantas filas de historico se siembran. Ver el docblock: menos no prueba nada. */
const FILAS_DE_HISTORICO = 20_000;

/** Cuantos empleados se reparten ese historico. */
const EMPLEADOS_DEL_HISTORICO = 20;

/** Turnos por empleado del historico de TRAMOS: 20 x 250 = 5.000 filas. */
const TRAMOS_POR_EMPLEADO = 250;

/**
 * Siembra el historico y devuelve el uuid del empleado con mas filas.
 */
function historicoDeEscaneos(): string
{
    $site = WorkforceFixtures::site('Hotel con historico');
    $device = AttendanceFixtures::device($site);

    $employees = [];

    for ($i = 0; $i < EMPLEADOS_DEL_HISTORICO; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Historico', 'H'.$i.Str::random(6));
        $id = DB::table('employees')->where('uuid', $uuid)->value('id');

        // Mismo patron que `WorkforceFixtures::onlySiteId()`: PHPStan 9 rechaza
        // castear `mixed`, y una asercion de Pest no le estrecha el tipo.
        $employees[$uuid] = is_numeric($id)
            ? (int) $id
            : throw new RuntimeException('El empleado sembrado no tiene clave interna.');
    }

    $ids = array_values($employees);
    $rows = [];
    $written = 0;

    for ($i = 0; $i < FILAS_DE_HISTORICO; $i++) {
        $employeeId = $ids[$i % count($ids)];
        $at = (new DateTimeImmutable('2022-01-01 06:00:00', new DateTimeZone('UTC')))
            ->modify('+'.(int) ($i / count($ids)).' hours')
            ->format('Y-m-d H:i:sP');

        $rows[] = [
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $device['id'],
            'employee_id' => $employeeId,
            'occurred_at' => $at,
            'recorded_at' => $at,
            'origin' => 'import',
            'intent' => 'auto',
            'result' => $i % 2 === 0 ? 'clock_in' : 'clock_out',
            'shift_entry_id' => null,
            'worked_minutes' => 0,
            'payload_fingerprint' => null,
            'client_meta' => '{}',
            'clock_skew_seconds' => null,
            'flagged_for_review' => false,
        ];

        // 4.000 filas por sentencia: el protocolo de PostgreSQL admite 65.535
        // parametros y estas filas tienen catorce columnas.
        if (count($rows) === 4_000) {
            DB::table('scan_events')->insert($rows);
            $written += count($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('scan_events')->insert($rows);
        $written += count($rows);
    }

    expect($written)->toBe(FILAS_DE_HISTORICO);

    // Estadisticas reales, con el rol dueño de la tabla: con el de la
    // aplicacion `ANALYZE` solo avisa, y el planificador decidiria sin saber
    // cuantas filas hay ni como se reparten por empleado.
    QueryPlans::analyze('scan_events');

    return array_key_first($employees);
}

/**
 * Comprueba que `scan_events` se resuelve por su indice y sin recorrerla entera.
 *
 * El indice es un parametro porque no todas las consultas calientes se sirven
 * del mismo: las dos del fichaje van por `(employee_id, occurred_at DESC)` y la
 * de la revision nocturna de RN-18, por el **parcial** de las filas marcadas.
 *
 * @param  array{sql: string, bindings: list<mixed>}  $query
 */
function resuelvePorElIndice(array $query, string $indice = 'scan_events_employee_id_occurred_at_index'): void
{
    $nodes = QueryPlans::nodes($query);

    expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer');

    $usaIndice = false;
    $recorreLaTabla = false;

    foreach ($nodes as $node) {
        $usaIndice = $usaIndice || ($node['Index Name'] ?? '') === $indice;
        $recorreLaTabla = $recorreLaTabla
            || (($node['Node Type'] ?? '') === 'Seq Scan' && ($node['Relation Name'] ?? '') === 'scan_events');
    }

    expect($usaIndice)->toBeTrue('El plan no usa '.$indice);
    expect($recorreLaTabla)->toBeFalse('El plan recorre scan_events de principio a fin');
}

it('resuelve el ultimo escaneo aceptado por el indice del historico', function (): void {
    $employeeUuid = historicoDeEscaneos();
    $scanLog = app(ScanLog::class);

    $queries = QueryPlans::selectsOn('scan_events', static fn () => $scanLog->lastAcceptedScanOf($employeeUuid));

    expect($queries)->not->toBeEmpty();

    resuelvePorElIndice($queries[0]);
})->group('RNF-P-02', 'RF-AT-12');

it('resuelve la ventana anti-rebote por el mismo indice', function (): void {
    $employeeUuid = historicoDeEscaneos();
    $scanLog = app(ScanLog::class);
    $instant = new DateTimeImmutable('2022-06-01 08:00:00', new DateTimeZone('UTC'));

    $queries = QueryPlans::selectsOn('scan_events',
        static fn () => $scanLog->acceptedScansAdjacentTo($employeeUuid, $instant)
    );

    // Son DOS consultas con `LIMIT 1` —el vecino anterior y el posterior— y no
    // un `ORDER BY abs(...)`: las dos tienen que caber en el indice.
    expect($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        resuelvePorElIndice($query);
    }
})->group('RNF-P-02', 'RF-AT-06');

it('resuelve los fichajes irreconciliables por el indice parcial de las marcadas', function (): void {
    // RN-18: la revision nocturna pregunta «¿que escaneos no se pudieron cuadrar
    // desde la ultima pasada?» sobre la misma tabla de veinte mil filas, y **sin
    // acotar por empleado**: los recorre todos. La unica forma de que eso no sea
    // un recorrido completo cada noche es el indice PARCIAL
    // `scan_events_flagged_for_review_index`, que solo contiene las filas
    // marcadas —aqui cinco de veinte mil—.
    //
    // Por eso la consulta filtra por `flagged_for_review` ademas de por el
    // resultado, aunque toda fila de RN-18 nazca marcada: sin esa condicion el
    // indice no es alcanzable, el plan cae a `Seq Scan` y nada mas en la suite
    // se entera.
    $employeeUuid = historicoDeEscaneos();
    $employee = DB::table('employees')->where('uuid', $employeeUuid)->first();

    // Mismo patron que arriba: PHPStan 9 rechaza castear `mixed`, y una
    // asercion de Pest no le estrecha el tipo.
    $siteId = $employee === null || ! is_numeric($employee->site_id)
        ? throw new RuntimeException('El empleado sembrado no tiene centro.')
        : (int) $employee->site_id;

    $device = AttendanceFixtures::device($siteId);
    $employeeId = $employee->id;

    $rows = [];

    for ($i = 0; $i < 5; $i++) {
        $at = (new DateTimeImmutable('2026-03-14 06:00:00', new DateTimeZone('UTC')))
            ->modify('+'.$i.' hours')
            ->format('Y-m-d H:i:sP');

        $rows[] = [
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $device['id'],
            'employee_id' => $employeeId,
            'occurred_at' => $at,
            'recorded_at' => $at,
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => 'rejected_out_of_order',
            'shift_entry_id' => null,
            'worked_minutes' => null,
            'payload_fingerprint' => null,
            'client_meta' => '{}',
            'clock_skew_seconds' => 0,
            'flagged_for_review' => true,
        ];
    }

    DB::table('scan_events')->insert($rows);

    // Otra vez: el planificador tiene que saber que las marcadas son una minoria.
    QueryPlans::analyze('scan_events');

    $port = app(OutOfOrderScans::class);

    $queries = QueryPlans::selectsOn('scan_events', static fn () => $port->outOfOrderBetween(
        new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-03-31 00:00:00', new DateTimeZone('UTC')),
    ));

    expect($queries)->toHaveCount(1);

    resuelvePorElIndice($queries[0], 'scan_events_flagged_for_review_index');
})->group('RN-18', 'RNF-P-02', 'RF-PR-01');

/**
 * Historico de TRAMOS (no de escaneos) y el uuid de un empleado con los suyos.
 *
 * Turnos de un dia cada uno, sin solaparse: la restriccion de exclusion los
 * comprueba uno a uno al insertarlos, asi que un historico que se pisara a si
 * mismo ni siquiera entraria.
 */
function historicoDeTramos(): string
{
    $site = WorkforceFixtures::site('Hotel con historico de tramos');

    $employees = [];

    for ($i = 0; $i < EMPLEADOS_DEL_HISTORICO; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Tramos', 'T'.$i.Str::random(6));
        $id = DB::table('employees')->where('uuid', $uuid)->value('id');

        $employees[$uuid] = is_numeric($id)
            ? (int) $id
            : throw new RuntimeException('El empleado sembrado no tiene clave interna.');
    }

    $rows = [];

    foreach ($employees as $employeeId) {
        for ($day = 0; $day < TRAMOS_POR_EMPLEADO; $day++) {
            $date = (new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC')))->modify('+'.$day.' days');
            $in = $date->setTime(6, 0);
            $out = $date->setTime(14, 0);

            $rows[] = [
                'uuid' => Str::uuid7()->toString(),
                'employee_id' => $employeeId,
                'site_id' => $site,
                'work_date' => $date->format('Y-m-d'),
                'clocked_in_at' => $in->format('Y-m-d H:i:sP'),
                'clocked_out_at' => $out->format('Y-m-d H:i:sP'),
                'duration_minutes' => 480,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $in->format('Y-m-d H:i:sP'),
                'updated_at' => $out->format('Y-m-d H:i:sP'),
            ];

            if (count($rows) === 1_000) {
                DB::table('shift_entries')->insert($rows);
                $rows = [];
            }
        }
    }

    if ($rows !== []) {
        DB::table('shift_entries')->insert($rows);
    }

    expect(DB::table('shift_entries')->count())->toBe(EMPLEADOS_DEL_HISTORICO * TRAMOS_POR_EMPLEADO);

    // Estadisticas reales, con el rol dueño de la tabla (ver arriba).
    QueryPlans::analyze('shift_entries');

    return array_key_first($employees);
}

it('resuelve el solape de RN-18 por el indice de la propia restriccion de exclusion', function (): void {
    // RN-18 a traves de jornadas. La pregunta —«¿tiene esta persona algun tramo
    // cerrado que siga vivo despues de este instante?»— se hace en el camino de
    // fichaje, **una vez por cada entrada**, sobre una tabla que en una
    // instalacion de cuatro años tiene millones de filas.
    //
    // Se escribe con el mismo `&&` y el mismo predicado parcial que
    // `shift_entries_no_overlap` justo para esto: el indice GiST que la
    // restriccion ya mantiene la sirve sin añadir ni un indice nuevo. Si alguien
    // la reescribe con `clocked_out_at > ?`, seguira siendo correcta y seguira
    // pasando todas las demas pruebas — y el cambio de turno dejara de caber en
    // 150 ms.
    $employeeUuid = historicoDeTramos();
    $repository = app(WorkDayRepository::class);
    $instant = new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('UTC'));

    $queries = QueryPlans::selectsOn('shift_entries',
        static fn () => $repository->closedEntryEndingAfter($employeeUuid, $instant)
    );

    // Dos: la que resuelve `employees.id` desde el uuid publico y la del solape.
    expect($queries)->not->toBeEmpty();

    $solape = array_values(array_filter(
        $queries,
        static fn (array $query): bool => str_contains($query['sql'], 'tstzrange'),
    ));

    expect($solape)->toHaveCount(1, 'La consulta del solape no se emitio o dejo de usar tstzrange.');

    $nodes = QueryPlans::nodes($solape[0]);

    expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer');

    $usaIndice = false;
    $recorreLaTabla = false;

    foreach ($nodes as $node) {
        $usaIndice = $usaIndice || ($node['Index Name'] ?? '') === 'shift_entries_no_overlap';
        $recorreLaTabla = $recorreLaTabla
            || (($node['Node Type'] ?? '') === 'Seq Scan' && ($node['Relation Name'] ?? '') === 'shift_entries');
    }

    expect($usaIndice)->toBeTrue('El plan no usa el indice de shift_entries_no_overlap')
        ->and($recorreLaTabla)->toBeFalse('El plan recorre shift_entries de principio a fin');
})->group('RN-18', 'RN-02', 'RNF-P-02');
