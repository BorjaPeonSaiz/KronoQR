<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;

/*
 * **Idempotencia concurrente del aviso de fichaje descartado** (RN-22, regla
 * dura 8, ADR-047): diez envios simultaneos del mismo `scan_id`, en procesos
 * distintos y con transacciones confirmadas de verdad, dejan **una** fila y diez
 * acuses identicos.
 *
 * Lo decide el UNIQUE de `discarded_scan_reports.scan_id` con `ON CONFLICT DO
 * NOTHING`, sin `SELECT` previo: es lo que este nivel de prueba exige a toda
 * escritura del quiosco (doc 02 §9.5).
 */

uses(CommittedDatabase::class);

const DISCARDED_SCAN_CONCURRENCY_REQUESTS = 10;

it('deja una sola fila y diez acuses identicos con diez avisos simultaneos del mismo scan_id', function (): void {
    $escenario = AttendanceFixtures::scenario();

    FrozenTime::at('2026-08-15 10:00:00');
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    // Diez del mismo dispositivo a la vez: por encima de su zona de seis por
    // minuto, que aqui no es lo que se mide.
    Config::set('kiosk.rate_limits.discarded_per_device', 100);

    $code = DB::table('employees')->where('uuid', $escenario['employee'])->value('employee_code');
    $code = is_string($code) ? $code : '';
    $scanId = Str::uuid7()->toString();

    $respuestas = ParallelRequests::run(
        DISCARDED_SCAN_CONCURRENCY_REQUESTS,
        static fn (): mixed => Api::as($escenario['token'])->post('/api/v1/scan/discarded', ['reports' => [[
            'scan_id' => $scanId,
            'occurred_at' => '2026-08-15T06:59:00Z',
            'kind' => 'pin',
            'http_status' => 400,
            'problem_type' => null,
            'discarded_at' => '2026-08-15T07:10:00Z',
            'employee_code' => $code,
        ]]]),
    );

    $cuerpos = array_map(static fn (array $r): string => json_encode($r['body'], JSON_THROW_ON_ERROR), $respuestas);
    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    expect($respuestas)->toHaveCount(DISCARDED_SCAN_CONCURRENCY_REQUESTS)
        ->and(array_values(array_unique($codigos)))->toBe([200])
        ->and(array_values(array_unique($cuerpos)))->toHaveCount(1)
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $scanId)->value('attribution'))->toBe('employee_code');
})->group('RN-22', 'RF-AT-07', 'RQ-03');
