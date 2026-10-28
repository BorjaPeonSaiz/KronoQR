<?php

declare(strict_types=1);

use App\Modules\Attendance\Infrastructure\Persistence\EloquentAnomalousPatternHistory;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentCredentialScans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos consultas de la deteccion nocturna de patrones entran por indice
 * (RF-PR-06, RNF-P-02; DB9 de la verificacion de la 2.1.0, que el doc 07
 * aceptaba hasta la 2.3.0; migracion `2026_10_28_100300`).
 *
 * - **La ventana de treinta dias** (`EloquentCredentialScans`) filtra por
 *   `(occurred_at AT TIME ZONE 'UTC')`, la expresion de
 *   `scan_events_occurred_at_utc_index`. Antes, `occurred_at BETWEEN` sin
 *   columna de cabecera era un `Parallel Seq Scan on scan_events` cada noche.
 * - **La historia de patrones** (`EloquentAnomalousPatternHistory`) repite el
 *   predicado de `incidents_anomalous_pattern_index`. Antes, `context ->>
 *   'pattern'` era un `Seq Scan on incidents`.
 *
 * Con un historico de casi dos años la ventana es el 4 % de los escaneos, y el
 * plan natural tiene que ir por el indice. La historia de patrones lee pocas
 * filas de una tabla que aqui es pequeña: para ella vale la red de
 * `enable_seqscan = off`, que comprueba que el indice sirve a la consulta.
 * El SQL se captura del adaptador ({@see QueryPlans}).
 */

uses(RefreshDatabaseAsOwner::class);

/** Personas del historico. */
const NIGHTLY_DETECTION_INDEX_EMPLEADOS = 60;

/** Dias de historico: 60 x 700 = 42.000 tramos y 84.000 escaneos. */
const NIGHTLY_DETECTION_INDEX_DIAS = 700;

/** El primer dia del historico. */
const NIGHTLY_DETECTION_INDEX_PRIMER_DIA = '2025-01-01';

/**
 * Tramos con entrada y salida desde el primer dia, una incidencia cada cinco
 * tramos y un indicio de patron cada noventa y siete. Devuelve los UUID de la
 * plantilla.
 *
 * @return list<string>
 */
function deteccionNocturnaHistorico(): array
{
    $site = WorkforceFixtures::site('Hotel de la deteccion nocturna');
    $device = AttendanceFixtures::device($site);
    $uuids = [];

    for ($i = 0; $i < NIGHTLY_DETECTION_INDEX_EMPLEADOS; $i++) {
        $uuids[] = WorkforceFixtures::employee($site);
    }

    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, ?, d::date,
               d + interval '6 hours', d + interval '14 hours', 480,
               'closed', 'qr_kiosk', 'qr_kiosk', 1, d + interval '14 hours', d + interval '14 hours'
          FROM employees e
         CROSS JOIN generate_series(?::date::timestamp AT TIME ZONE 'UTC',
                                    (?::date + ?::integer - 1)::timestamp AT TIME ZONE 'UTC', interval '1 day') AS d
         ORDER BY d, e.id
        SQL, [$site, NIGHTLY_DETECTION_INDEX_PRIMER_DIA, NIGHTLY_DETECTION_INDEX_PRIMER_DIA, NIGHTLY_DETECTION_INDEX_DIAS]);

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
        INSERT INTO incidents
            (employee_id, work_date, shift_entry_id, type, severity, status, detected_at, context,
             created_at, updated_at)
        SELECT se.employee_id, se.work_date, se.id, 'long_shift', 'medium', 'open',
               se.clocked_out_at, '{}'::jsonb, se.clocked_out_at, se.clocked_out_at
          FROM shift_entries se
         WHERE se.id % 5 = 0
        SQL);

    DB::statement(<<<'SQL'
        INSERT INTO incidents
            (employee_id, work_date, shift_entry_id, type, severity, status, detected_at, context,
             created_at, updated_at)
        SELECT se.employee_id, se.work_date, NULL, 'anomalous_pattern', 'medium', 'open',
               se.clocked_out_at, jsonb_build_object('pattern', 'kiosk_coincidence'),
               se.clocked_out_at, se.clocked_out_at
          FROM shift_entries se
         WHERE se.id % 97 = 0
        SQL);

    QueryPlans::analyze('employees', 'shift_entries', 'scan_events', 'incidents');

    return $uuids;
}

it('lee la ventana de treinta dias por scan_events_occurred_at_utc_index, sin recorrer scan_events', function (): void {
    deteccionNocturnaHistorico();

    $scans = new EloquentCredentialScans(DB::connection());
    $to = new DateTimeImmutable(NIGHTLY_DETECTION_INDEX_PRIMER_DIA.' 00:00:00', new DateTimeZone('UTC'))
        ->modify('+'.(NIGHTLY_DETECTION_INDEX_DIAS - 1).' days');
    $from = $to->modify('-30 days');

    $found = [];
    $queries = QueryPlans::selectsOn('scan_events', static function () use ($scans, $from, $to, &$found): void {
        $found = $scans->kioskScansBetween($from, $to, new DateTimeZone('Europe/Madrid'));
    });

    expect($queries)->toHaveCount(1)
        // La ventana devuelve lo mismo que antes: treinta dias con su entrada
        // y su salida. El extremo final es la medianoche del ultimo dia, que
        // aun no tiene ningun escaneo.
        ->and($found)->toHaveCount(NIGHTLY_DETECTION_INDEX_EMPLEADOS * 30 * 2);

    $nodes = QueryPlans::nodes($queries[0]);

    expect(QueryPlans::scansSequentially($nodes, 'scan_events'))->toBeFalse('La ventana nocturna recorre scan_events entera')
        ->and(QueryPlans::scansSequentially($nodes, 'shift_entries'))->toBeFalse('La ventana nocturna recorre shift_entries entera')
        ->and(QueryPlans::usesIndex($nodes, 'scan_events_occurred_at_utc_index'))->toBeTrue('La ventana nocturna no usa scan_events_occurred_at_utc_index');
})->group('RF-PR-06', 'RNF-P-02');

it('incluye los dos extremos de la ventana, al microsegundo', function (): void {
    $site = WorkforceFixtures::site('Hotel de los extremos');
    $device = AttendanceFixtures::device($site);
    $employee = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));

    foreach (['2026-03-01 00:00:00.000000+00', '2026-03-31 23:59:59.999999+00', '2026-04-01 00:00:00.000000+00'] as $at) {
        DB::table('scan_events')->insert([
            'scan_id' => Str::uuid7()->toString(), 'device_id' => $device['id'], 'employee_id' => $employee,
            'occurred_at' => $at, 'recorded_at' => $at, 'origin' => 'qr_kiosk', 'intent' => 'auto',
            'result' => 'rejected_debounce', 'shift_entry_id' => null, 'worked_minutes' => 0,
            'client_meta' => json_encode([]),
        ]);
    }

    $found = new EloquentCredentialScans(DB::connection())->kioskScansBetween(
        new DateTimeImmutable('2026-03-01 01:00:00', new DateTimeZone('Europe/Madrid')),
        new DateTimeImmutable('2026-04-01 01:59:59.999999', new DateTimeZone('Europe/Madrid')),
        new DateTimeZone('Europe/Madrid'),
    );

    // Los dos primeros caen dentro (el segundo, en el ultimo microsegundo); el
    // tercero es el instante siguiente al extremo. Los extremos llegan en hora
    // de Madrid, uno en invierno (UTC+1) y otro en verano (UTC+2), y se comparan
    // en UTC.
    expect(array_map(static fn ($scan): string => $scan->occurredAt->format('Y-m-d H:i:s.u'), $found))
        ->toBe(['2026-03-01 00:00:00.000000', '2026-03-31 23:59:59.999999']);
})->group('RF-PR-06');

it('la historia de patrones tiene el indice parcial que la sirve', function (): void {
    $uuids = deteccionNocturnaHistorico();

    $history = new EloquentAnomalousPatternHistory(DB::connection());

    $states = [];
    $queries = QueryPlans::selectsOn('incidents', static function () use ($history, $uuids, &$states): void {
        $states = $history->forEmployees($uuids, 'kiosk_coincidence');
    });

    expect($queries)->toHaveCount(1)
        ->and(array_filter($states, static fn ($state): bool => $state->hasOpen))->not->toBeEmpty();

    DB::statement('SET LOCAL enable_seqscan = off');

    $nodes = QueryPlans::nodes($queries[0]);

    expect(QueryPlans::scansSequentially($nodes, 'incidents'))->toBeFalse('Ningun indice sirve a la historia de patrones')
        ->and(QueryPlans::usesIndex($nodes, 'incidents_anomalous_pattern_index'))->toBeTrue('La historia de patrones no usa incidents_anomalous_pattern_index');
})->group('RF-PR-06', 'RNF-P-02');
