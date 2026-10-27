<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanResult;

/*
 * **Las clasificaciones de `scan_events.result`**, sin base de datos.
 *
 * Cada tabla de `ScanResult` se escribe a mano y un desenlace nuevo sin entrada
 * lanza en vez de caer en un valor por omision. Estas pruebas fijan el
 * contenido y obligan a clasificar cada caso nuevo: un `case` anadido al enum
 * sin su entrada falla aqui antes que en un adaptador.
 */

it('fija los rechazos de credencial que excluyen el uso de RN-16 y los desfases de RN-20', function (): void {
    expect(ScanResult::credentialRejections())->toBe([
        'rejected_unknown',
        'rejected_revoked',
        'rejected_signature',
        'rejected_suspended',
    ]);
})->group('RN-16', 'RN-20', 'RN-25');

it('deja fuera de los rechazos de credencial el anti-rebote, el de RN-18 y el descarte del servidor', function (ScanResult $result): void {
    expect($result->isCredentialRejection())->toBeFalse();
})->with([
    'anti-rebote (ADR-031)' => [ScanResult::REJECTED_DEBOUNCE],
    'fuera de orden (RN-18)' => [ScanResult::REJECTED_OUT_OF_ORDER],
    'descarte del servidor (ADR-061)' => [ScanResult::DISCARDED_UNPROCESSABLE],
])->group('RN-16', 'RN-18', 'RN-22');

it('clasifica cada desenlace en todas sus tablas, sin dejar ninguno por omision', function (ScanResult $result): void {
    // Lanzan si el caso no tiene entrada: es lo que obliga a clasificar uno nuevo.
    $result->isCredentialRejection();
    $result->action();

    // Ningun desenlace aceptado es un rechazo de credencial.
    expect($result->isAccepted() && $result->isCredentialRejection())->toBeFalse();
})->with(ScanResult::cases())->group('RN-16', 'RF-AT-07');
