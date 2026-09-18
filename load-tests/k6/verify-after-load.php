<?php

declare(strict_types=1);

/*
 * Verificacion POSTERIOR a la prueba de carga.
 *
 * Una medida de latencia no dice nada si lo que quedo escrito esta mal. Esto es
 * lo que comprueba que el pico de RNF-P-06 dejo un registro correcto:
 *
 *   RN-06     la proyeccion `daily_totals` cuadra con los tramos origen y
 *             `projection_divergence_total` no ha subido durante la pasada.
 *   RF-AT-07  un `scan_id` = una fila.
 *   RQ-03     ningun empleado con dos tramos abiertos.
 *   RF-KI-04  los lotes llegaron con la salida ANTES que la entrada y el
 *             servidor los ordeno: el empleado acabo con un tramo cerrado.
 *   RN-18     el elemento imposible del lote quedo REGISTRADO como
 *             `rejected_out_of_order` y marcado para revision, no devuelto a la
 *             cola con un 503 que el quiosco reintentaria para siempre.
 *   RNF-P-02  las dos consultas calientes del fichaje resuelven por
 *             `scan_events_employee_id_occurred_at_index`.
 *
 * Se ejecuta DENTRO del contenedor `app`, como el aprovisionamiento:
 *
 *   php artisan tinker --execute="include '/tmp/k6/verify-after-load.php';"
 *
 * Tinker devuelve 0 aunque el include lance, asi que el exito NO se puede leer
 * del codigo de salida: al terminar bien deja `/tmp/k6/verify-ok.json`, y
 * `run.sh` exige ese fichero.
 *
 * EL PLAN DE LAS CONSULTAS SE MIDE SOBRE EL SQL QUE EMITE EL PRODUCTO, no sobre
 * uno transcrito a mano: se registra `DB::listen`, se llama al puerto real
 * (`ScanLog`) y se hace `EXPLAIN` sobre lo que salio. Un SQL copiado se queda
 * viejo en cuanto alguien toca el adaptador, y entonces esta comprobacion pasa a
 * medir un plan que el producto ya no ejecuta.
 */

use App\Modules\Attendance\Application\Port\ScanLog;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support.php';

k6_assert_test_database();

$problems = [];
$warnings = [];
$report = [];

$say = static function (string $line): void {
    echo $line."\n";
};

$check = static function (string $requirement, bool $passed, string $detail) use (&$problems, $say): void {
    $say(($passed ? 'OK    ' : 'FALLA ').$requirement.': '.$detail);

    if (! $passed) {
        $problems[] = $requirement.': '.$detail;
    }
};

$warn = static function (string $line) use (&$warnings, $say): void {
    $say('AVISO '.$line);
    $warnings[] = $line;
};

// --- Quien es la carga -------------------------------------------------------

$siteId = (int) DB::table('sites')->orderBy('id')->value('id');
$departmentId = k6_department_id($siteId);

if ($departmentId === null) {
    throw new RuntimeException(
        'No existe el departamento «'.K6_DEPARTMENT_NAME.'»: ejecuta provision-fixtures.php antes.'
    );
}

k6_assert_no_foreign_codes($departmentId);

$fixtures = k6_read_json(K6_WORK_DIR.'/k6-fixtures.json');
$employeeUuids = array_values(array_filter(
    (array) ($fixtures['employee_uuids'] ?? []),
    static fn (mixed $uuid): bool => is_string($uuid),
));

$employeeIds = k6_load_employees($departmentId)->pluck('id')->all();

if ($employeeIds === []) {
    throw new RuntimeException('No hay empleados de carga: ejecuta provision-fixtures.php antes.');
}

// LA VENTANA SE MIDE POR `recorded_at`, NO POR `occurred_at`. Los dos instantes
// existen precisamente porque no son lo mismo (regla dura 9): un lote trae
// `occurred_at` de hasta diez minutos antes, asi que una ventana sobre el
// momento real se lleva por delante los escaneos de la pasada anterior y el
// cuadre empieza a contar fichajes que esta pasada no hizo. `recorded_at` es la
// recepcion en servidor y siempre cae dentro de la pasada; el aprovisionamiento
// —que ocurre antes de la primera peticion— marca su principio.
//
// Por lo mismo, los tramos se acotan por `created_at` y `updated_at`, que son
// tiempo de servidor, y no por `clocked_in_at`, que es tiempo real.
$generatedAt = \is_string($fixtures['generated_at'] ?? null) ? (string) $fixtures['generated_at'] : null;
$since = $generatedAt !== null
    ? gmdate('Y-m-d H:i:sP', (int) strtotime($generatedAt))
    : gmdate('Y-m-d H:i:sP', time() - 3_600);

$say('Empleados de la carga: '.count($employeeIds).' en el departamento «'.K6_DEPARTMENT_NAME.'».');
$say('Ventana de la pasada: desde '.$since.'.');
$say('');

// --- RF-AT-07: un scan_id, una fila ------------------------------------------

$scanCounts = DB::table('scan_events')
    ->whereIn('employee_id', $employeeIds)
    ->where('recorded_at', '>=', $since)
    ->selectRaw('count(*) as total, count(distinct scan_id) as distinct_ids')
    ->first();

$total = (int) ($scanCounts->total ?? 0);
$distinct = (int) ($scanCounts->distinct_ids ?? 0);

$check(
    'RF-AT-07',
    $total === $distinct && $total > 0,
    $total.' escaneos registrados y '.$distinct.' scan_id distintos '
    .'(el UNIQUE de scan_events.scan_id es la garantia; aqui se afirma)'
);

$byResult = DB::table('scan_events')
    ->whereIn('employee_id', $employeeIds)
    ->where('recorded_at', '>=', $since)
    ->groupBy('result')
    ->selectRaw('result, count(*) as total')
    ->pluck('total', 'result')
    ->all();

$report['scan_events'] = array_map(intval(...), $byResult);
$say('      desenlaces: '.json_encode($report['scan_events'], JSON_THROW_ON_ERROR));

// --- RQ-03: ningun empleado con dos tramos abiertos --------------------------

$doubleOpen = DB::table('shift_entries')
    ->whereIn('employee_id', $employeeIds)
    ->where('status', 'open')
    ->whereNull('superseded_by_id')
    ->groupBy('employee_id')
    ->havingRaw('count(*) > 1')
    ->pluck('employee_id')
    ->count();

$check(
    'RQ-03',
    $doubleOpen === 0,
    $doubleOpen.' empleados con mas de un tramo abierto '
    .'(el indice unico parcial one_open_shift_per_employee es la ultima linea de defensa)'
);

// --- Los tramos cuadran con los escaneos que los crearon ---------------------

// ADR-024: una pausa son DOS tramos, asi que `break_start` abre uno y
// `break_end` cierra otro exactamente igual que `clock_in` y `clock_out`. Contar
// solo los dos primeros dejaria fuera media jornada en cuanto la instalacion
// active el fichaje de pausa.
$opening = (int) ($report['scan_events']['clock_in'] ?? 0) + (int) ($report['scan_events']['break_start'] ?? 0);
$closing = (int) ($report['scan_events']['clock_out'] ?? 0) + (int) ($report['scan_events']['break_end'] ?? 0);

$entries = DB::table('shift_entries')
    ->whereIn('employee_id', $employeeIds)
    ->where('created_at', '>=', $since)
    ->whereNull('superseded_by_id')
    ->selectRaw('count(*) as total')
    ->first();

$openedEntries = (int) ($entries->total ?? 0);

$check(
    'RN-06/entradas',
    $openedEntries === $opening,
    $openedEntries.' tramos vigentes creados en la ventana frente a '.$opening
    .' respuestas que abren tramo (clock_in + break_start)'
);

// EL CIERRE SE SIGUE POR `scan_events.shift_entry_id`, y no por una marca de
// tiempo de `shift_entries`. Un fichaje recalcula la jornada entera (regla dura
// 7), asi que `updated_at` se mueve tambien en los tramos que ya estaban
// cerrados desde antes y contar por ahi da un numero que no significa nada. La
// columna que dice QUE tramo cerro cada escaneo es la que hay que mirar: cada
// cierre apunta a un tramo, a uno distinto, y ese tramo tiene que haber quedado
// cerrado.
$closingScans = DB::table('scan_events')
    ->whereIn('employee_id', $employeeIds)
    ->where('recorded_at', '>=', $since)
    ->whereIn('result', ['clock_out', 'break_end'])
    ->selectRaw('count(*) as total, count(distinct shift_entry_id) as entries, count(shift_entry_id) as linked')
    ->first();

$closingTotal = (int) ($closingScans->total ?? 0);
$closingEntries = (int) ($closingScans->entries ?? 0);
$closingLinked = (int) ($closingScans->linked ?? 0);

$closedEntries = $closingEntries === 0 ? 0 : DB::table('shift_entries')
    ->whereIn('id', DB::table('scan_events')
        ->whereIn('employee_id', $employeeIds)
        ->where('recorded_at', '>=', $since)
        ->whereIn('result', ['clock_out', 'break_end'])
        ->whereNotNull('shift_entry_id')
        ->select('shift_entry_id'))
    ->whereNotNull('clocked_out_at')
    ->count();

$check(
    'RN-06/salidas',
    $closingTotal === $closing
    && $closingLinked === $closingTotal
    && $closingEntries === $closingTotal
    && $closedEntries === $closingEntries,
    $closing.' respuestas que cierran tramo; '.$closingLinked.' con tramo enlazado, '
    .$closingEntries.' tramos distintos y '.$closedEntries.' de ellos con hora de salida'
);

// --- RN-06: la proyeccion es el total de los tramos cerrados -----------------

// `daily_totals` es una proyeccion RECONSTRUIBLE y se recalcula entera en la
// transaccion del fichaje (regla dura 7): el total del dia tiene que ser la suma
// de los tramos cerrados de ese dia, ni un minuto mas. **Se recorren las dos
// direcciones**: una jornada con tramos y sin fila de proyeccion, y una fila de
// proyeccion que ya no tiene tramos vivos detras —la que deja una correccion que
// anula el ultimo tramo del dia— son el mismo defecto visto del derecho y del
// reves, y mirar solo una lo deja pasar la mitad de las veces.
$workDateFloor = gmdate('Y-m-d', strtotime($since) - 86_400);

$actualByDay = DB::table('shift_entries')
    ->whereIn('employee_id', $employeeIds)
    ->where('work_date', '>=', $workDateFloor)
    ->whereNull('superseded_by_id')
    ->where('status', '<>', 'voided')
    ->groupBy('employee_id', 'work_date')
    ->selectRaw(
        'employee_id, work_date, '
        .'sum(coalesce(duration_minutes, 0)) filter (where clocked_out_at is not null) as closed_minutes'
    )
    ->get()
    ->keyBy(static fn (object $row): string => $row->employee_id.'@'.$row->work_date);

$projected = DB::table('daily_totals')
    ->whereIn('employee_id', $employeeIds)
    ->where('work_date', '>=', $workDateFloor)
    ->get(['employee_id', 'work_date', 'total_minutes'])
    ->keyBy(static fn (object $row): string => $row->employee_id.'@'.$row->work_date);

$mismatches = 0;

foreach ($actualByDay as $stableKey => $day) {
    $row = $projected->get($stableKey);

    if ($row === null || (int) $row->total_minutes !== (int) $day->closed_minutes) {
        $mismatches++;
    }
}

foreach ($projected as $stableKey => $row) {
    // Una fila de proyeccion con minutos y sin ningun tramo vigente detras.
    if (! $actualByDay->has($stableKey) && (int) $row->total_minutes !== 0) {
        $mismatches++;
    }
}

$check(
    'RN-06/proyeccion',
    $mismatches === 0,
    $mismatches.' jornadas en las que daily_totals no es la suma de sus tramos cerrados '
    .'(contadas en los dos sentidos: proyeccion sin tramos y tramos sin proyeccion)'
);

// --- RF-PR-02: el contador de divergencias no ha subido ----------------------

$promFile = k6_projection_prom_file();
$divergence = k6_textfile_metric($promFile, 'projection_divergence_total');
$divergenceBefore = $fixtures['projection_divergence_before'] ?? null;
$divergenceBefore = is_numeric($divergenceBefore) ? (int) $divergenceBefore : null;

if ($divergence === null) {
    // `null` no es `0`. No se da por bueno lo que no se ha podido leer.
    $warn('RF-PR-02: no hay fichero .prom en '.$promFile.'; no se puede afirmar nada sobre '
        .'projection_divergence_total. Comprueba METRICS_TEXTFILE_ENABLED y que la reconciliacion haya corrido.');
} else {
    $check(
        'RF-PR-02',
        $divergenceBefore === null || $divergence === $divergenceBefore,
        'projection_divergence_total '.$divergence.' tras la reconciliacion, '
        .($divergenceBefore === null ? 'sin valor de partida' : $divergenceBefore.' antes de la carga '
            .'('.($divergence - $divergenceBefore).' nuevas)')
    );
}

$report['projection_divergence'] = ['before' => $divergenceBefore, 'after' => $divergence];

// --- RF-KI-04: el lote se procesa por occurred_at, no por orden de llegada ---

// Los escenarios de lote envian, por cada empleado de su rebanada, la SALIDA
// antes que la ENTRADA. Si el servidor procesara en orden de llegada intentaria
// cerrar un tramo que no existe: ese elemento saldria `503` y el empleado se
// quedaria con el tramo ABIERTO. Que acabe cerrado es la prueba de que ordeno.
$geometry = (array) ($fixtures['geometry'] ?? []);
$batchUuids = [];

if ($employeeUuids !== [] && $geometry !== []) {
    $instances = max(1, (int) ($geometry['instances'] ?? 1));
    $perInstance = max(1, (int) ($geometry['cards_per_instance'] ?? 1));
    $batchCards = max(0, (int) ($geometry['batch_cards'] ?? 0));
    $offsetInSlice = $perInstance - $batchCards;

    for ($instance = 0; $instance < $instances; $instance++) {
        $start = $instance * $perInstance + $offsetInSlice;

        for ($card = 0; $card < $batchCards; $card++) {
            if (isset($employeeUuids[$start + $card])) {
                $batchUuids[] = $employeeUuids[$start + $card];
            }
        }
    }
}

if ($batchUuids === []) {
    $warn('RF-KI-04: los fixtures no traen la geometria de rebanadas; no se puede comprobar el orden del lote.');
} else {
    $batchIds = DB::table('employees')->whereIn('uuid', $batchUuids)->pluck('id', 'uuid')->all();

    // QUIEN LLEGA A LA VENTANA YA FICHADO NO SIRVE PARA ESTO, y no porque el
    // producto falle. Con un tramo abierto de antes, el primer elemento del lote
    // —el mas antiguo por `occurred_at`— lo CIERRA en lugar de abrir, el segundo
    // abre uno nuevo, y el empleado acaba con un tramo abierto habiendo el
    // servidor ordenado perfectamente. Contarlo como fallo seria culpar al
    // servidor de la pasada anterior.
    $carriedOver = DB::table('shift_entries')
        ->whereIn('employee_id', array_values($batchIds))
        ->whereNull('superseded_by_id')
        ->where('created_at', '<', $since)
        ->where(static function ($query) use ($since): void {
            $query->whereNull('clocked_out_at')->orWhere('updated_at', '>=', $since);
        })
        ->distinct()
        ->pluck('employee_id')
        ->all();

    // Solo los que empezaron limpios y recibieron el par COMPLETO en esta
    // ventana: los demas —lote frenado por el borde, elemento conservado en la
    // cola— no dicen nada del orden y no se cuentan ni a favor ni en contra.
    $pairShape = DB::table('scan_events')
        ->whereNotIn('employee_id', $carriedOver === [] ? [0] : $carriedOver)
        ->whereIn('employee_id', array_values($batchIds))
        ->where('recorded_at', '>=', $since)
        ->whereIn('result', ['clock_in', 'clock_out'])
        ->groupBy('employee_id')
        ->selectRaw(
            "employee_id, count(*) filter (where result = 'clock_in') as ins, "
            ."count(*) filter (where result = 'clock_out') as outs"
        )
        ->get()
        ->filter(static fn (object $row): bool => (int) $row->ins === 1 && (int) $row->outs === 1);

    $paired = $pairShape->pluck('employee_id')->all();

    $closed = $paired === [] ? 0 : DB::table('shift_entries')
        ->whereIn('employee_id', $paired)
        ->where('created_at', '>=', $since)
        ->whereNull('superseded_by_id')
        ->whereNotNull('clocked_out_at')
        ->distinct()
        ->count('employee_id');

    $stillOpen = count($paired) - $closed;

    $report['batch_pairs'] = [
        'completos' => count($paired),
        'cerrados' => $closed,
        'abiertos' => $stillOpen,
        'arrastraban_tramo_abierto' => count($carriedOver),
    ];

    if ($paired === []) {
        $warn('RF-KI-04: ningun empleado de lote recibio el par completo en esta pasada '
            .'(el borde freno los lotes); el orden por occurred_at no se ha podido comprobar.');
    } else {
        $check(
            'RF-KI-04',
            $stillOpen === 0,
            $closed.' de '.count($paired).' empleados con par de lote acabaron con el tramo CERRADO '
            .'(la salida llego antes que la entrada y el servidor las ordeno por occurred_at)'
        );
    }
}

// --- RNF-P-02: los planes de las dos consultas calientes ---------------------

// El SQL sale del PRODUCTO. Se escucha la conexion, se llama al puerto real y se
// hace `EXPLAIN` sobre lo que emitio el adaptador, con sus mismos bindings.
$sampleUuid = DB::table('scan_events')
    ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
    ->where('employees.department_id', $departmentId)
    ->groupBy('employees.uuid')
    ->orderByRaw('count(*) desc')
    ->limit(1)
    ->value('employees.uuid');

// UN PLAN SOBRE UNA TABLA PEQUENA NO DICE NADA. Con pocas filas, PostgreSQL
// elige un recorrido completo porque de verdad es mas barato, y exigir el indice
// ahi convierte esta comprobacion en un aviso de «tu entorno tiene pocos datos»
// disfrazado de defecto. El umbral es el mismo volumen que siembra
// `ScanLogIndexUsageTest`, que es la prueba que guarda el plan de forma
// determinista; esto es la confirmacion sobre el volumen real de la instalacion.
const K6_PLAN_MIN_ROWS = 20_000;

$scanEventRows = DB::table('scan_events')->count();
$planIsMeaningful = $scanEventRows >= K6_PLAN_MIN_ROWS;

$captured = [];

DB::listen(static function ($query) use (&$captured): void {
    $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
});

$scanLog = app(ScanLog::class);

$captured = [];
$scanLog->lastAcceptedScanOf((string) $sampleUuid);
$lastAcceptedQueries = $captured;

$captured = [];
$scanLog->acceptedScansAdjacentTo((string) $sampleUuid, new DateTimeImmutable('now', new DateTimeZone('UTC')));
$adjacentQueries = $captured;

$report['plans'] = [];
$report['scan_events_rows'] = $scanEventRows;

$explain = static function (string $name, array $queries) use ($check, $warn, $planIsMeaningful, &$report): void {
    // De todas las consultas que emitio el puerto, la que interesa es la que
    // toca `scan_events`: la resolucion de `employees.id` es otra pregunta y
    // tiene su propio indice.
    $relevant = array_values(array_filter(
        $queries,
        static fn (array $query): bool => str_contains($query['sql'], 'scan_events')
            && str_starts_with(ltrim(strtolower($query['sql'])), 'select'),
    ));

    if ($relevant === []) {
        $check('RNF-P-02/plan', false, $name.': el puerto no emitio ninguna consulta sobre scan_events');

        return;
    }

    foreach ($relevant as $index => $query) {
        $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query['sql'], $query['bindings']);
        $plan = (string) ($explained[0]->{'QUERY PLAN'} ?? '');

        // Recorriendo el ARBOL del plan y no buscando subcadenas: la consulta
        // lleva un `LEFT JOIN` con `shift_entries`, y un recorrido completo de
        // ESA tabla —correcto, es pequeña— se atribuia a `scan_events`.
        $usesIndex = k6_plan_uses_index($plan, 'scan_events_employee_id_occurred_at_index');
        $scansTable = k6_plan_scans_sequentially($plan, 'scan_events');

        $label = $name.($index > 0 ? ' ['.($index + 1).']' : '');
        $report['plans'][$label] = ['index' => $usesIndex, 'seq_scan' => $scansTable];

        $detail = $label.': '.($usesIndex ? 'usa el indice' : 'NO usa scan_events_employee_id_occurred_at_index')
            .($scansTable ? ' y recorre la tabla' : '');

        if (! $planIsMeaningful) {
            $warn('RNF-P-02/plan: '.$detail.' — no evaluable: scan_events tiene menos de '
                .K6_PLAN_MIN_ROWS.' filas y con esa cantidad el recorrido completo es legitimo. '
                .'Sube K6_HISTORY_DAYS/K6_HISTORY_EMPLOYEES o mide contra un entorno con historico.');

            continue;
        }

        $check('RNF-P-02/plan', $usesIndex && ! $scansTable, $detail);
    }
};

$explain('ultimo escaneo aceptado (lastAcceptedScanOf)', $lastAcceptedQueries);
$explain('ventana anti-rebote (acceptedScansAdjacentTo)', $adjacentQueries);

// --- Lo que k6 dijo frente a lo que la base guarda ---------------------------

$summary = k6_read_json(K6_WORK_DIR.'/summary.json');

// --- RN-18: el irreconciliable queda REGISTRADO y marcado para revision ------

// Un elemento de lote que no se puede reconciliar —una salida anterior a la
// entrada del turno que tendria que cerrar— ya no devuelve `503`. Devolverlo
// significaba «conservalo en la cola», y la cola de ese quiosco se quedaba
// reintentandolo para siempre. Ahora responde `422` con el cuerpo generico y
// **deja fila**: `result = 'rejected_out_of_order'` y `flagged_for_review`, para
// que una persona lo mire. Lo que se afirma aqui es justo eso: que cada `422`
// que conto k6 tiene su fila, y que ninguna de esas filas se quedo sin marcar
// —una fila que nadie va a revisar es una jornada que nadie va a arreglar—.
$outOfOrder = DB::table('scan_events')
    ->whereIn('employee_id', $employeeIds)
    ->where('recorded_at', '>=', $since)
    ->where('result', 'rejected_out_of_order')
    ->selectRaw('count(*) as total, count(*) filter (where flagged_for_review) as flagged')
    ->first();

$outOfOrderRows = (int) ($outOfOrder->total ?? 0);
$outOfOrderFlagged = (int) ($outOfOrder->flagged ?? 0);
$reportedUnreconcilable = $summary['totals']['batch_unreconcilable'] ?? null;

$report['out_of_order'] = [
    'filas' => $outOfOrderRows,
    'marcadas_para_revision' => $outOfOrderFlagged,
    'contadas_por_k6' => $reportedUnreconcilable,
];

if (! is_numeric($reportedUnreconcilable)) {
    $warn('RN-18: no hay summary.json con el recuento de irreconciliables; solo se comprueba la marca.');
}

// LA DESIGUALDAD ES A UN SOLO LADO, y no por prudencia: el escaneo previo con
// el que el guion abre el tramo del caso imposible puede producir SU PROPIA fila
// de RN-18 —si la tarjeta llega con un tramo abierto posterior a `seedAt`, ese
// escaneo es tambien un cierre irreconciliable—, y esa fila no sale en el
// recuento de elementos de lote. Que sobren filas es correcto; que falten, no.
$check(
    'RN-18',
    $outOfOrderRows === $outOfOrderFlagged
    && (! is_numeric($reportedUnreconcilable) || $outOfOrderRows >= (int) $reportedUnreconcilable),
    'k6 conto '.($reportedUnreconcilable ?? 'n/d').' elementos irreconciliables (422) en los lotes y la '
    .'base guarda '.$outOfOrderRows.' filas rejected_out_of_order, '.$outOfOrderFlagged
    .' de ellas marcadas para revision'
);

if (is_numeric($reportedUnreconcilable) && $outOfOrderRows !== (int) $reportedUnreconcilable) {
    $warn('RN-18: hay '.($outOfOrderRows - (int) $reportedUnreconcilable).' filas rejected_out_of_order '
        .'de mas respecto a los elementos de lote. Lo esperable es que salgan del escaneo previo del caso '
        .'imposible; si son muchas mas, mira que otro camino las esta produciendo.');
}

// --- Lo que k6 dijo frente a lo que la base guarda ---------------------------

$reportedRealtime = $summary['totals']['shift_producing_realtime'] ?? null;
$reportedBatch = $summary['totals']['shift_producing_batch'] ?? null;

if (is_numeric($reportedRealtime) && is_numeric($reportedBatch)) {
    $reported = (int) $reportedRealtime + (int) $reportedBatch;
    $written = $opening + $closing;

    // ASIMETRICO A PROPOSITO. Que la base tenga MAS escaneos que respuestas
    // conto k6 es normal bajo saturacion: una peticion cuyo cliente se canso de
    // esperar se registro igual, y eso es exactamente lo que la regla dura 19
    // quiere. Lo que no puede pasar es lo contrario: una respuesta que anuncio
    // un tramo que no existe.
    $check(
        'RNF-P-06/registro',
        $reported <= $written,
        'k6 conto '.$reported.' respuestas con tramo y la base guarda '.$written.' escaneos que lo producen'
        .($reported < $written
            ? ' (la diferencia son peticiones que el servidor atendio y cuya respuesta no llego al cliente)'
            : '')
    );
} else {
    $warn('RNF-P-06/registro: no hay summary.json con los recuentos de k6; no se ha podido contrastar '
        .'lo que dijo el generador con lo que guarda la base.');
}

// --- Veredicto ---------------------------------------------------------------

$say('');

if ($problems !== []) {
    throw new RuntimeException(
        'La verificacion posterior a la carga encontro '.count($problems).' problemas: '
        ."\n  - ".implode("\n  - ", $problems)
    );
}

file_put_contents(K6_WORK_DIR.'/verify-ok.json', json_encode([
    'verified_at' => gmdate('c'),
    'employees' => count($employeeIds),
    'since' => $since,
    'warnings' => $warnings,
    'report' => $report,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$say('VERIFICACION POSTERIOR: verde'.($warnings === [] ? '' : ' con '.count($warnings).' avisos')
    .'. RN-06, RF-AT-07, RQ-03, RF-KI-04 y RN-18 se sostienen sobre lo que quedo escrito.');
