<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\Collector\KioskCollector;

/*
 * La forma de cada quiosco en el paquete de diagnostico (RF-PD-09, PR13).
 *
 * Unitaria y sin base de datos: `KioskCollector::present()` es donde se decide
 * que sale y como, y la consulta que lo alimenta se prueba aparte, en
 * `Integration/Product/DiagnosticsKioskTelemetryAndVolumeTest`.
 */

/**
 * Una fila como la devuelve PostgreSQL, con las columnas que NO deben salir.
 *
 * @return array<string, mixed>
 */
function filaDeQuioscoCompleta(): array
{
    return [
        'uuid' => '0190a6b2-0000-7000-8000-000000000001',
        'status' => 'active',
        'app_version' => '2.2.0',
        'last_seen_at' => '2026-09-30 08:00:00.123456+00',
        'pending_queue_size' => 37,
        'oldest_pending_at' => '2026-09-29 05:58:31+00',
        'battery_level' => 14,
        'battery_charging' => false,
        'paired_at' => '2026-07-02 10:00:00+00',
        'token_expires_at' => '2026-09-30 23:30:00+02',
        // Lo que nunca sale, aunque la consulta lo trajera.
        'name' => 'Tablet de Marta',
        'token_hash' => str_repeat('a', 64),
        'id' => 7,
    ];
}

it('lleva caducidad del token, emparejamiento, pendiente mas antiguo y bateria en la forma del contrato', function (): void {
    $kiosk = KioskCollector::present(filaDeQuioscoCompleta());

    expect(array_keys($kiosk))->toBe(KioskCollector::FIELDS)
        ->and($kiosk)->toBe([
            'uuid' => '0190a6b2-0000-7000-8000-000000000001',
            'status' => 'active',
            'app_version' => '2.2.0',
            'last_seen_at' => '2026-09-30T08:00:00.123456Z',
            'pending_queue_size' => 37,
            'oldest_pending_at' => '2026-09-29T05:58:31.000000Z',
            'battery_level' => 14,
            'battery_charging' => false,
            'paired_at' => '2026-07-02T10:00:00.000000Z',
            // 23:30 en +02 son las 21:30 UTC del mismo dia: la fecha es la UTC.
            'token_expires_on' => '2026-09-30',
        ]);
})->group('RF-PD-09');

it('de la caducidad del token solo sale el dia, en UTC', function (): void {
    // 01:00 en +02 del dia 1 son las 23:00 UTC del dia anterior.
    $kiosk = KioskCollector::present([...filaDeQuioscoCompleta(), 'token_expires_at' => '2026-10-01 01:00:00+02']);

    expect($kiosk['token_expires_on'])->toBe('2026-09-30')
        ->and((string) json_encode($kiosk))->not->toContain('23:00');
})->group('RF-PD-09');

it('nunca lleva el nombre del quiosco, el hash del token ni el id interno', function (): void {
    $json = (string) json_encode(KioskCollector::present(filaDeQuioscoCompleta()));

    expect($json)->not->toContain('Marta')
        ->and($json)->not->toContain(str_repeat('a', 64))
        ->and($json)->not->toContain('"name"')
        ->and($json)->not->toContain('token_hash')
        ->and($json)->not->toContain('"id"');
})->group('RF-PD-09', 'RS-08');

it('sin latido ni token, los campos nuevos son null y la clave sigue ahi', function (): void {
    // Una tablet recien emparejada que aun no ha latido, o una desvinculada:
    // el elemento tiene la misma forma, con null donde no hay dato.
    $kiosk = KioskCollector::present([
        'uuid' => '0190a6b2-0000-7000-8000-000000000002',
        'status' => 'revoked',
        'app_version' => null,
        'last_seen_at' => null,
        'pending_queue_size' => null,
        'oldest_pending_at' => null,
        'battery_level' => null,
        'battery_charging' => null,
        'paired_at' => null,
        'token_expires_at' => null,
    ]);

    expect(array_keys($kiosk))->toBe(KioskCollector::FIELDS)
        ->and($kiosk['pending_queue_size'])->toBe(0)
        ->and($kiosk['oldest_pending_at'])->toBeNull()
        ->and($kiosk['battery_level'])->toBeNull()
        ->and($kiosk['battery_charging'])->toBeNull()
        ->and($kiosk['paired_at'])->toBeNull()
        ->and($kiosk['token_expires_on'])->toBeNull();
})->group('RF-PD-09');

it('interpreta el booleano de la bateria venga como venga del driver', function (mixed $raw, ?bool $expected): void {
    expect(KioskCollector::present([...filaDeQuioscoCompleta(), 'battery_charging' => $raw])['battery_charging'])
        ->toBe($expected);
})->with([
    'booleano verdadero' => [true, true],
    'booleano falso' => [false, false],
    'texto t' => ['t', true],
    'texto f' => ['f', false],
    'entero 1' => [1, true],
    'entero 0' => [0, false],
    'cualquier otra cosa' => ['quizas', null],
])->group('RF-PD-09');

it('acota la bateria a 0..100 y una fecha ilegible no rompe el paquete', function (): void {
    $kiosk = KioskCollector::present([
        ...filaDeQuioscoCompleta(),
        'battery_level' => 140,
        'pending_queue_size' => -3,
        'oldest_pending_at' => 'no es una fecha',
        'token_expires_at' => 'tampoco',
    ]);

    expect($kiosk['battery_level'])->toBe(100)
        ->and($kiosk['pending_queue_size'])->toBe(0)
        ->and($kiosk['oldest_pending_at'])->toBeNull()
        ->and($kiosk['token_expires_on'])->toBeNull();
})->group('RF-PD-09');
