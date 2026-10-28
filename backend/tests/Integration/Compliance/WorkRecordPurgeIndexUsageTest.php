<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La purga de retencion no recorre tablas enteras (RL-02, RNF-P-02; V21-5-BE-1
 * y V5-BD-1 de la 2.2.1, migraciones `2026_10_28_100000` y `2026_10_28_100100`).
 *
 * Lo normal es que no haya nada vencido, o casi nada, y que la tabla de
 * escaneos tenga años de historico vigente. Hasta la 2.3.0, el `inspect`
 * semanal y cada lote de `work_record_purge_batch()` eran un `Seq Scan on
 * scan_events` para devolver cero filas, porque el filtro era un `LEFT JOIN`
 * con un `OR` que ningun indice servia.
 *
 * ## Dos formas de comprobarlo
 *
 * - **`inspect()` se captura**, como en el resto de pruebas de planes
 *   ({@see QueryPlans}): el SQL que emite el adaptador, con `EXPLAIN`. Sin
 *   `Seq Scan` de las tablas que crecen, y con `enable_seqscan = off` como red,
 *   que el indice nuevo sirva de verdad a la expresion de la consulta.
 * - **La funcion no se puede capturar**: sus sentencias viven en PL/pgSQL. Se
 *   miden los **bloques que toca** con `EXPLAIN (ANALYZE, BUFFERS)` de la
 *   llamada, que suma los de todas sus sentencias. Sin nada vencido, una
 *   funcion que entra por indices toca unos cientos; una que recorre
 *   `scan_events` toca, como poco, todas sus paginas. No depende del reloj.
 *
 * En la sesion del rol dueño y en una transaccion que se deshace
 * ({@see RefreshDatabaseAsOwner}): `ANALYZE` ve lo sembrado y sus estadisticas
 * no sobreviven a la prueba.
 */

uses(RefreshDatabaseAsOwner::class);

/** Personas del historico. */
const WORK_RECORD_PURGE_INDEX_EMPLEADOS = 40;

/** Dias de historico vigente por persona: 40 x 700 = 28.000 tramos y 56.000 escaneos. */
const WORK_RECORD_PURGE_INDEX_DIAS_VIGENTES = 700;

/** Dias vencidos por persona antes del corte: el atraso de una purga normal. */
const WORK_RECORD_PURGE_INDEX_DIAS_VENCIDOS = 7;

/**
 * El historico: tramos con su entrada y su salida, un rechazo sin tramo cada
 * diez tramos, una incidencia cada cinco y un aviso descartado cada dos horas,
 * desde `DIAS_VENCIDOS` antes del corte hasta `DIAS_VIGENTES` despues.
 *
 * @return array{cutoff: string}
 */
function purgaPorIndiceHistorico(): array
{
    /** @var object{cutoff: string} $row */
    $row = DB::selectOne(<<<'SQL'
        SELECT public.work_record_purge_max_cutoff((now() AT TIME ZONE 'UTC')::date, 4)::text AS cutoff
        SQL);

    $site = WorkforceFixtures::site('Hotel de la purga por indice');
    $device = AttendanceFixtures::device($site);

    for ($i = 0; $i < WORK_RECORD_PURGE_INDEX_EMPLEADOS; $i++) {
        AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    }

    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, ?, d::date,
               d + interval '6 hours', d + interval '14 hours', 480,
               'closed', 'qr_kiosk', 'qr_kiosk', 1, d + interval '14 hours', d + interval '14 hours'
          FROM employees e
         CROSS JOIN generate_series((?::date - ?::integer)::timestamp AT TIME ZONE 'UTC',
                                    (?::date + ?::integer)::timestamp AT TIME ZONE 'UTC', interval '1 day') AS d
         ORDER BY d, e.id
        SQL, [
        $site,
        $row->cutoff, WORK_RECORD_PURGE_INDEX_DIAS_VENCIDOS,
        $row->cutoff, WORK_RECORD_PURGE_INDEX_DIAS_VIGENTES,
    ]);

    DB::statement(<<<'SQL'
        INSERT INTO scan_events
            (scan_id, device_id, employee_id, occurred_at, recorded_at, origin, intent, result,
             shift_entry_id, worked_minutes, client_meta)
        SELECT gen_random_uuid(), ?, se.employee_id, m.at, m.at + interval '2 seconds', 'qr_kiosk', 'auto',
               m.result, se.id, m.minutes, '{}'::jsonb
          FROM shift_entries se
         CROSS JOIN LATERAL (VALUES (se.clocked_in_at, 'clock_in', 0), (se.clocked_out_at, 'clock_out', 480)) AS m(at, result, minutes)
         ORDER BY m.at
        SQL, [$device['id']]);

    DB::statement(<<<'SQL'
        INSERT INTO scan_events
            (scan_id, device_id, employee_id, occurred_at, recorded_at, origin, intent, result,
             shift_entry_id, worked_minutes, client_meta)
        SELECT gen_random_uuid(), ?, NULL, se.clocked_in_at - interval '1 minute',
               se.clocked_in_at - interval '58 seconds', 'qr_kiosk', 'auto', 'rejected_unknown',
               NULL, NULL, '{}'::jsonb
          FROM shift_entries se
         WHERE se.id % 10 = 0
        SQL, [$device['id']]);

    DB::statement(<<<'SQL'
        INSERT INTO incidents
            (employee_id, work_date, shift_entry_id, type, severity, status, detected_at, context,
             created_at, updated_at)
        SELECT se.employee_id, se.work_date, se.id, 'long_shift', 'medium', 'open',
               se.clocked_out_at, '{}'::jsonb, se.clocked_out_at, se.clocked_out_at
          FROM shift_entries se
         WHERE se.id % 5 = 0
        SQL);

    DB::statement(<<<'SQL'
        INSERT INTO discarded_scan_reports
            (scan_id, device_id, origin, occurred_at, discarded_at, recorded_at, http_status,
             attribution, already_recorded, source)
        SELECT gen_random_uuid(), ?, 'qr_kiosk', d, d + interval '1 day', d + interval '1 day', 409,
               'none', false, 'kiosk'
          FROM generate_series((?::date - ?::integer)::timestamp AT TIME ZONE 'UTC',
                               (?::date + ?::integer)::timestamp AT TIME ZONE 'UTC', interval '2 hours') AS d
        SQL, [
        $device['id'],
        $row->cutoff, WORK_RECORD_PURGE_INDEX_DIAS_VENCIDOS,
        $row->cutoff, WORK_RECORD_PURGE_INDEX_DIAS_VIGENTES,
    ]);

    QueryPlans::analyze('shift_entries', 'scan_events', 'incidents', 'discarded_scan_reports');

    return ['cutoff' => $row->cutoff];
}

/**
 * Las consultas de `inspect()` para ese corte, capturadas.
 *
 * @return list<array{sql: string, bindings: list<mixed>}>
 */
function purgaPorIndiceInspect(string $cutoff): array
{
    $archive = new DatabaseWorkRecordArchive(DB::connection());

    return QueryPlans::capture(
        static fn (): array => $archive->inspect(new DateTimeImmutable($cutoff.' 00:00:00', new DateTimeZone('UTC'))),
        static fn (string $sql): bool => str_starts_with(ltrim($sql), 'select'),
    );
}

/**
 * Los bloques que toca una llamada a la funcion, en una subtransaccion que se
 * deshace.
 *
 * **La segunda llamada, no la primera.** La primera de la sesion compila la
 * funcion y planifica cada sentencia, y eso lee el catalogo por los mismos
 * buffers: cientos de bloques que no dicen nada de `scan_events`.
 */
function purgaPorIndiceBloques(string $cutoff): int
{
    $root = null;

    for ($call = 0; $call < 2; $call++) {
        DB::statement('SAVEPOINT purga_por_indice');

        try {
            $root = QueryPlans::root('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ', [
                'sql' => 'SELECT * FROM public.'.DatabaseWorkRecordArchive::PURGE_FUNCTION.'(?::date, 1000)',
                'bindings' => [$cutoff],
            ]);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT purga_por_indice');
        }
    }

    $hit = $root['Shared Hit Blocks'] ?? 0;
    $read = $root['Shared Read Blocks'] ?? 0;

    return (is_numeric($hit) ? (int) $hit : 0) + (is_numeric($read) ? (int) $read : 0);
}

function purgaPorIndicePaginas(string $table): int
{
    $pages = DB::table('pg_class')->where('relname', $table)->value('relpages');

    return is_numeric($pages) ? (int) $pages : 0;
}

it('sin nada vencido, el inspect semanal no recorre scan_events', function (): void {
    ['cutoff' => $cutoff] = purgaPorIndiceHistorico();

    // Lo normal: un corte anterior a todo el historico.
    $queries = purgaPorIndiceInspect(new DateTimeImmutable($cutoff)->modify('-30 days')->format('Y-m-d'));

    expect($queries)->toHaveCount(6);

    foreach ($queries as $query) {
        // Solo `scan_events`: las incidencias y los avisos son pocos en este
        // historico, y leer entera una tabla pequeña es el plan correcto. Que
        // tienen indice lo comprueba la red de la prueba siguiente. Con un
        // atraso, tambien `scan_events` se puede leer entera con este volumen
        // (60.000 escaneos), y es el plan barato; con cuatro años, no.
        expect(QueryPlans::scansSequentially(QueryPlans::nodes($query), 'scan_events'))
            ->toBeFalse('inspect() recorre scan_events entera: '.$query['sql']);
    }
})->group('RL-02', 'RNF-P-02');

it('cada consulta de inspect() tiene el indice que la sirve, tambien la de los avisos descartados', function (): void {
    ['cutoff' => $cutoff] = purgaPorIndiceHistorico();

    $queries = purgaPorIndiceInspect($cutoff);

    // La red: con el recorrido secuencial penalizado, el plan tiene que poder
    // entrar por el indice nuevo. Si la expresion de la consulta y la del
    // indice dejaran de coincidir, aqui seguiria el `Seq Scan`.
    DB::statement('SET LOCAL enable_seqscan = off');

    $used = [];

    foreach ($queries as $query) {
        $nodes = QueryPlans::nodes($query);

        foreach (['scan_events', 'incidents', 'discarded_scan_reports'] as $table) {
            expect(QueryPlans::scansSequentially($nodes, $table))->toBeFalse('Ningun indice sirve a '.$table.' en: '.$query['sql']);
        }

        foreach ($nodes as $node) {
            $index = $node['Index Name'] ?? null;

            if (is_string($index)) {
                $used[$index] = true;
            }
        }
    }

    expect(array_keys($used))->toContain(
        'scan_events_unattached_age_index',
        'incidents_work_date_index',
        'discarded_scan_reports_age_index',
        'shift_entries_work_date_index',
    );
})->group('RL-02', 'RNF-P-02');

it('sin nada vencido, la funcion de purga toca menos de una decima parte de las paginas de scan_events', function (): void {
    ['cutoff' => $cutoff] = purgaPorIndiceHistorico();

    // Un corte anterior a todo lo sembrado: nada vencido, que es lo normal.
    $antes = new DateTimeImmutable($cutoff)->modify('-30 days')->format('Y-m-d');

    $blocks = purgaPorIndiceBloques($antes);
    $pages = purgaPorIndicePaginas('scan_events');

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, sprintf("\nV21-5-BE-1: funcion sin nada vencido: %d bloques; scan_events tiene %d paginas\n", $blocks, $pages));
    }

    // Una funcion que recorriera `scan_events` tocaria, como poco, cada una de
    // sus paginas. Con indices son unos cientos, sea cual sea el historico.
    expect($pages)->toBeGreaterThan(500)
        ->and($blocks)->toBeGreaterThan(0)
        ->and($blocks)->toBeLessThan(intdiv($pages, 10));
})->group('RL-02', 'RNF-P-02');

it('con una semana vencida, la funcion borra lo vencido y nada mas', function (): void {
    ['cutoff' => $cutoff] = purgaPorIndiceHistorico();

    $vencidos = DB::table('shift_entries')->where('work_date', '<', $cutoff)->count();
    $vigentes = DB::table('shift_entries')->where('work_date', '>=', $cutoff)->count();
    $escaneosVigentes = DB::table('scan_events')
        ->leftJoin('shift_entries', 'shift_entries.id', '=', 'scan_events.shift_entry_id')
        ->where(static function ($query) use ($cutoff): void {
            $query->where('shift_entries.work_date', '>=', $cutoff)
                ->orWhere(static function ($query) use ($cutoff): void {
                    $query->whereNull('scan_events.shift_entry_id')
                        ->whereRaw('LEAST(scan_events.occurred_at, scan_events.recorded_at) >= ?::date::timestamp AT TIME ZONE \'UTC\'', [$cutoff]);
                });
        })
        ->count();

    $blocks = purgaPorIndiceBloques($cutoff);

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, sprintf("\nV21-5-BE-1: funcion con %d tramos vencidos: %d bloques\n", $vencidos, $blocks));
    }

    /** @var list<object{dataset: string, deleted: int|string}> $rows */
    $rows = DB::select('SELECT dataset, deleted FROM public.'.DatabaseWorkRecordArchive::PURGE_FUNCTION.'(?::date, 1000)', [$cutoff]);
    $deleted = [];

    foreach ($rows as $row) {
        $deleted[$row->dataset] = (int) $row->deleted;
    }

    expect($vencidos)->toBe(WORK_RECORD_PURGE_INDEX_EMPLEADOS * WORK_RECORD_PURGE_INDEX_DIAS_VENCIDOS)
        ->and($deleted['shift_entries'] ?? null)->toBe($vencidos)
        ->and(DB::table('shift_entries')->count())->toBe($vigentes)
        ->and(DB::table('shift_entries')->where('work_date', '<', $cutoff)->exists())->toBeFalse()
        ->and(DB::table('scan_events')->count())->toBe($escaneosVigentes)
        ->and(DB::table('incidents')->where('work_date', '<', $cutoff)->exists())->toBeFalse()
        ->and(DB::table('discarded_scan_reports')
            ->whereRaw('LEAST(occurred_at, recorded_at) < ?::date::timestamp AT TIME ZONE \'UTC\'', [$cutoff])
            ->exists())->toBeFalse();
})->group('RL-02', 'RNF-P-02');
