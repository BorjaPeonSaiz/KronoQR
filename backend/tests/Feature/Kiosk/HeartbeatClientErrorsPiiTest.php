<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\SeededPersonalData;

/*
 * El latido del quiosco NO DEJA ENTRAR NI UN DATO PERSONAL en `error_events`
 * por `client_errors` (ADR-048; RF-PD-15, RL-19, regla dura 21).
 *
 * La otra puerta de entrada de errores de cliente. Mismos datos sembrados que
 * `ClientErrorsPiiTest`, en los mismos sitios, salvo los valores anidados: el
 * contrato del latido los rechaza con `400` antes de llegar al historico, y eso
 * tambien se comprueba.
 */

uses(RefreshDatabase::class);

/** Codigos del catalogo del quiosco que se van alternando. */
const HEARTBEAT_CLIENT_ERRORS_PII_CODES = ['kiosk.camera.stream_lost', 'kiosk.heartbeat.failed', 'kiosk.unhandled_error'];

it('no guarda ni un dato sembrado por client_errors', function (): void {
    $quiosco = AttendanceFixtures::scenario();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'client_errors' => SeededPersonalData::clientReports(HEARTBEAT_CLIENT_ERRORS_PII_CODES, nested: false),
    ])->assertOk();

    $filas = DB::table('error_events')->get();
    $texto = SeededPersonalData::textOfRows($filas);

    expect(SeededPersonalData::allLeaksIn($texto))->toBe([])
        ->and($texto)->not->toContain('first_name');

    // Control positivo: los grupos estan, son del quiosco, de los tres codigos,
    // y llevan el `device_id` del token y no el que mandaba el cuerpo.
    expect($filas->count())->toBeGreaterThan(1)
        ->and($filas->pluck('source')->unique()->values()->all())->toBe(['kiosk'])
        ->and($filas->pluck('code')->unique()->sort()->values()->all())->toBe(HEARTBEAT_CLIENT_ERRORS_PII_CODES)
        ->and($filas->pluck('device_id')->unique()->values()->all())->toBe([$quiosco['deviceUuid']])
        ->and($texto)->toContain(SeededPersonalData::TECHNICAL);
})->group('RF-PD-15', 'RL-19');

it('rechaza un valor anidado en el contexto sin guardar nada', function (): void {
    $quiosco = AttendanceFixtures::scenario();

    $respuesta = Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'client_errors' => [[
            'code' => 'kiosk.camera.stream_lost',
            'occurred_at' => '2026-10-03T09:00:00Z',
            'app_version' => '2.2.0',
            'context' => ['meta' => ['name' => 'Rosa Ficticiana']],
        ]],
    ]);

    expect($respuesta->status())->toBeGreaterThanOrEqual(400)
        ->and($respuesta->status())->toBeLessThan(500)
        ->and(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15', 'RL-19');
