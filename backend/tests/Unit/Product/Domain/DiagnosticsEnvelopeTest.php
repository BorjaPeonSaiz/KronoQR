<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Support\CanonicalJson;
use App\Modules\Product\Domain\Support\DiagnosticsEnvelope;

/*
 * LA FORMA DEL SOBRE, UNA SOLA VEZ PARA QUIEN LO CIERRA Y QUIEN LO ABRE
 * (ADR-020, enmienda del 01-11-2026; RL-19, RF-PD-09; revision de la 33.8).
 *
 * El producto y `tools/diagnostics-opener/` tenian cada uno su copia del
 * formato, del identificador de clave y de la descripcion de `personal_data`.
 * Ahora es una funcion pura del dominio; aqui se prueba sola, sin criptografia.
 * Que el abridor la carga en vez de copiarla lo mira `DiagnosticsSealingTest`,
 * y la ida y vuelta real con el abridor es de `DiagnosticsPathsAndSealingTest`.
 */

it('el identificador de una clave son los 16 primeros hexadecimales de su SHA-256', function (): void {
    $publica = str_repeat("\x07", 32);

    expect(DiagnosticsEnvelope::keyId($publica))->toBe(substr(hash('sha256', $publica), 0, 16))
        ->and(DiagnosticsEnvelope::keyId($publica))->toHaveLength(DiagnosticsEnvelope::KEY_ID_LENGTH)
        ->and(DiagnosticsEnvelope::KEY_ID_LENGTH)->toBe(16)
        ->and(DiagnosticsEnvelope::keyId('otra'))->not->toBe(DiagnosticsEnvelope::keyId($publica));
})->group('RL-19', 'RF-PD-09');

it('el formato, la version y el algoritmo son los del contrato', function (): void {
    expect(DiagnosticsEnvelope::FORMAT)->toBe('kronoqr-diagnostics-sealed')
        ->and(DiagnosticsEnvelope::ENVELOPE_VERSION)->toBe(1)
        ->and(DiagnosticsEnvelope::ALGORITHM)->toBe('x25519-sealedbox+xchacha20poly1305-ietf')
        ->and(DiagnosticsEnvelope::ENVELOPE_FIELDS)->toBe(['sealed_key', 'nonce', 'ciphertext']);
})->group('RL-19', 'RF-PD-09');

it('los datos asociados son el documento canonico sin los tres campos del sobre', function (): void {
    $cabecera = ['format' => DiagnosticsEnvelope::FORMAT, 'manifest' => ['b' => 2, 'a' => 1], 'contents' => ['status' => 'omitted']];
    $documento = [...$cabecera, 'sealed_key' => 'x', 'nonce' => 'y', 'ciphertext' => 'z'];

    expect(DiagnosticsEnvelope::associatedData($documento))->toBe(CanonicalJson::encode($cabecera))
        ->and(DiagnosticsEnvelope::associatedData($cabecera))->toBe(CanonicalJson::encode($cabecera))
        ->and(DiagnosticsEnvelope::associatedData($documento))->not->toContain('ciphertext')
        ->and(DiagnosticsEnvelope::associatedData([...$cabecera, 'format' => 'otro']))
        ->not->toBe(DiagnosticsEnvelope::associatedData($cabecera));
})->group('RL-19', 'RS-08');

it('describe personal_data sin valores: filas, columnas en orden de aparicion y posiciones como texto', function (): void {
    $paquete = [
        // El paquete descifrado lleva tambien su manifiesto: no se mira.
        'manifest' => ['sha256' => 'abc'],
        'personal_data' => [
            'period_days' => 7,
            'employees' => ['items' => [['uuid' => 'u-1', 'full_name' => 'Lucia'], 'no es una fila', ['uuid' => 'u-2', 'status' => 'active']]],
            'incidents' => ['items' => [['abierta', 'scan']]],
            'shift_entries' => ['items' => []],
            'devices' => ['status' => 'unavailable'],
        ],
    ];

    $contenido = DiagnosticsEnvelope::contents($paquete, 7, 512);

    expect($contenido)->toBe([
        'period_days' => 7,
        'plaintext_bytes' => 512,
        'status' => 'included',
        'collections' => [
            'employees' => ['rows' => 3, 'columns' => ['uuid', 'full_name', 'status']],
            'incidents' => ['rows' => 1, 'columns' => ['0', '1']],
            'shift_entries' => ['rows' => 0, 'columns' => []],
        ],
    ])->and((string) json_encode($contenido))->not->toContain('Lucia');
})->group('RL-19', 'RF-PD-09');

it('dice omitted o unavailable sin colecciones', function (array $secciones, string $estado): void {
    expect(DiagnosticsEnvelope::contents($secciones, 31, 9))
        ->toBe(['period_days' => 31, 'plaintext_bytes' => 9, 'status' => $estado]);
})->with([
    'no cupo' => [['personal_data' => ['status' => 'omitted', 'items' => []]], 'omitted'],
    'sin seccion' => [['installation' => []], 'unavailable'],
    'seccion que no es un mapa' => [['personal_data' => 'nada'], 'unavailable'],
    'colecciones sin items' => [['personal_data' => ['employees' => ['total' => 0], 'scan_events' => 'x']], 'unavailable'],
])->group('RL-19', 'RF-PD-09');
