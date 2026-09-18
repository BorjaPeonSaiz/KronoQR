<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
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
 * peor que no tenerla.
 *
 * VOLUMEN DELIBERADO: veinte mil filas. Sobre una tabla de veinte, PostgreSQL
 * elige un recorrido completo porque es mas barato, y la prueba fallaria sin
 * que hubiera nada roto. Con veinte mil, el recorrido deja de ser una opcion
 * razonable y el plan pasa a decir algo.
 */

uses(RefreshDatabase::class);

/** Cuantas filas de historico se siembran. Ver el docblock: menos no prueba nada. */
const FILAS_DE_HISTORICO = 20_000;

/** Cuantos empleados se reparten ese historico. */
const EMPLEADOS_DEL_HISTORICO = 20;

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

    // Sin estadisticas frescas el planificador sigue creyendo que la tabla esta
    // vacia y elige el recorrido completo: se estaria midiendo el momento en
    // que paso autovacuum, no el plan.
    DB::statement('ANALYZE scan_events');

    return array_key_first($employees);
}

/**
 * El SQL que el PUERTO emite sobre `scan_events`, con sus bindings.
 *
 * @return list<array{sql: string, bindings: list<mixed>}>
 */
function consultasDelPuerto(Closure $llamada): array
{
    $capturadas = [];

    DB::listen(static function ($query) use (&$capturadas): void {
        $capturadas[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $llamada();

    return array_values(array_filter(
        $capturadas,
        static fn (array $query): bool => str_contains($query['sql'], 'scan_events')
            && str_starts_with(ltrim(strtolower($query['sql'])), 'select'),
    ));
}

/**
 * El plan de ejecucion de esa consulta, ya aplanado en nodos.
 *
 * SE RECORRE EL ARBOL Y NO SE BUSCAN SUBCADENAS. La consulta lleva un
 * `LEFT JOIN` con `shift_entries`, asi que el JSON del plan contiene a la vez
 * «Seq Scan» y «scan_events» en cuanto la segunda tabla se recorre entera —lo
 * que es correcto cuando es pequeña—. Comprobarlo con `str_contains` daba un
 * fallo que aparecia y desaparecia segun cuantos tramos hubiera sembrados.
 *
 * (El mismo recorrido vive en `load-tests/k6/support.php` para la verificacion
 * posterior a la prueba de carga. No se comparte a proposito: son dos arboles
 * distintos del repositorio y atar la suite del backend a `load-tests/` por ocho
 * lineas costaria mas de lo que ahorra.)
 *
 * @param  array{sql: string, bindings: list<mixed>}  $query
 * @return list<array<string, mixed>>
 */
function nodosDelPlan(array $query): array
{
    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query['sql'], $query['bindings']);
    $raiz = raizDelPlan((string) ($explained[0]->{'QUERY PLAN'} ?? ''));

    return $raiz === null ? [] : nodosBajo($raiz);
}

/**
 * El nodo raiz del JSON de `EXPLAIN`, o `null` si no se pudo leer.
 *
 * @return array<mixed, mixed>|null
 */
function raizDelPlan(string $explained): ?array
{
    /** @var mixed $decoded */
    $decoded = json_decode($explained, true);
    /** @var mixed $first */
    $first = is_array($decoded) ? ($decoded[0] ?? null) : null;
    /** @var mixed $root */
    $root = is_array($first) ? ($first['Plan'] ?? null) : null;

    return is_array($root) ? $root : null;
}

/**
 * @param  array<mixed, mixed>  $raiz
 * @return list<array<string, mixed>>
 */
function nodosBajo(array $raiz): array
{
    /** @var list<array<string, mixed>> $nodes */
    $nodes = [];
    /** @var list<array<string, mixed>> $pending */
    $pending = [$raiz];

    while ($pending !== []) {
        $current = array_pop($pending);
        $nodes[] = $current;

        /** @var mixed $children */
        $children = $current['Plans'] ?? [];

        foreach (is_array($children) ? $children : [] as $child) {
            if (is_array($child)) {
                $pending[] = $child;
            }
        }
    }

    return $nodes;
}

/**
 * Comprueba que `scan_events` se resuelve por su indice y sin recorrerla entera.
 *
 * @param  array{sql: string, bindings: list<mixed>}  $query
 */
function resuelvePorElIndice(array $query): void
{
    $nodes = nodosDelPlan($query);

    expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer');

    $usaIndice = false;
    $recorreLaTabla = false;

    foreach ($nodes as $node) {
        $usaIndice = $usaIndice || ($node['Index Name'] ?? '') === 'scan_events_employee_id_occurred_at_index';
        $recorreLaTabla = $recorreLaTabla
            || (($node['Node Type'] ?? '') === 'Seq Scan' && ($node['Relation Name'] ?? '') === 'scan_events');
    }

    expect($usaIndice)->toBeTrue('El plan no usa scan_events_employee_id_occurred_at_index');
    expect($recorreLaTabla)->toBeFalse('El plan recorre scan_events de principio a fin');
}

it('resuelve el ultimo escaneo aceptado por el indice del historico', function (): void {
    $employeeUuid = historicoDeEscaneos();
    $scanLog = app(ScanLog::class);

    $queries = consultasDelPuerto(static fn () => $scanLog->lastAcceptedScanOf($employeeUuid));

    expect($queries)->not->toBeEmpty();

    resuelvePorElIndice($queries[0]);
})->group('RNF-P-02', 'RF-AT-12');

it('resuelve la ventana anti-rebote por el mismo indice', function (): void {
    $employeeUuid = historicoDeEscaneos();
    $scanLog = app(ScanLog::class);
    $instant = new DateTimeImmutable('2022-06-01 08:00:00', new DateTimeZone('UTC'));

    $queries = consultasDelPuerto(
        static fn () => $scanLog->acceptedScansAdjacentTo($employeeUuid, $instant)
    );

    // Son DOS consultas con `LIMIT 1` —el vecino anterior y el posterior— y no
    // un `ORDER BY abs(...)`: las dos tienen que caber en el indice.
    expect($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        resuelvePorElIndice($query);
    }
})->group('RNF-P-02', 'RF-AT-06');
