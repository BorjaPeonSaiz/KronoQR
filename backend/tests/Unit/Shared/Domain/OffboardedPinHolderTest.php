<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\OffboardedPinHolder;
use App\Modules\Shared\Domain\ValueObject\PinVerification;

/*
 * RN-20, D15 (enmienda del 29-10-2026 de ADR-043): el PIN correcto de una
 * persona de baja viaja del verificador a la fila de `scan_events` **sin dejar
 * de ser el mismo rechazo** hacia fuera. Ni `PinVerification` ni
 * `CredentialResolution` entregan un `employeeUuid()`, y el motivo es el
 * `UNKNOWN` de cualquier PIN que no verifica.
 */

const OFFBOARDED_PIN_HOLDER_UUID = '0199f0c2-0000-7000-8000-0000000000dd';

function identificadaPorPinTrasLaBaja(): OffboardedPinHolder
{
    return OffboardedPinHolder::of(OFFBOARDED_PIN_HOLDER_UUID, '2026-03-01', '2026-08-15', new DateTimeZone('Europe/Madrid'));
}

it('es un rechazo sin empleado, sin claim y sin bloqueo', function (): void {
    $verification = PinVerification::rejectedOffboarded(identificadaPorPinTrasLaBaja());

    expect($verification->isVerified())->toBeFalse()
        ->and($verification->employeeUuid())->toBeNull()
        ->and($verification->isLocked())->toBeFalse()
        ->and($verification->claim())->toBeNull()
        ->and($verification->offboardedHolder()?->employeeUuid)->toBe(OFFBOARDED_PIN_HOLDER_UUID)
        // Los demas desenlaces no la llevan nunca.
        ->and(PinVerification::rejected()->offboardedHolder())->toBeNull()
        ->and(PinVerification::locked(300)->offboardedHolder())->toBeNull()
        ->and(PinVerification::verified(OFFBOARDED_PIN_HOLDER_UUID)->offboardedHolder())->toBeNull();
})->group('RN-20', 'RS-03');

it('baja a una resolucion que hacia fuera es el mismo rechazo UNKNOWN', function (): void {
    $resolution = CredentialResolution::rejectedWithOffboardedPinHolder(identificadaPorPinTrasLaBaja());

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->employeeUuid())->toBeNull()
        ->and($resolution->rejectionReason())->toBe(CredentialRejectionReason::UNKNOWN)
        ->and($resolution->pinClaim())->toBeNull()
        ->and($resolution->holder())->toBeNull()
        ->and($resolution->offboardedPinHolder()?->period->terminatedOn)->toBe('2026-08-15')
        ->and(CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN)->offboardedPinHolder())->toBeNull();
})->group('RN-20', 'RS-03');

it('no se construye sin UUID, con fechas que no son civiles o con el cese antes del alta', function (string $uuid, string $hiredOn, string $terminatedOn): void {
    expect(fn (): OffboardedPinHolder => OffboardedPinHolder::of($uuid, $hiredOn, $terminatedOn, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin UUID' => ['', '2026-03-01', '2026-08-15'],
    'alta que no existe' => [OFFBOARDED_PIN_HOLDER_UUID, '2026-02-30', '2026-08-15'],
    'cese con hora' => [OFFBOARDED_PIN_HOLDER_UUID, '2026-03-01', '2026-08-15 10:00:00'],
    'cese antes del alta' => [OFFBOARDED_PIN_HOLDER_UUID, '2026-08-16', '2026-08-15'],
])->group('RN-20');

it('admite el cese el mismo dia del alta', function (): void {
    $holder = OffboardedPinHolder::of(OFFBOARDED_PIN_HOLDER_UUID, '2026-08-15', '2026-08-15', new DateTimeZone('UTC'));

    expect($holder->period->hiredOn)->toBe($holder->period->terminatedOn)
        ->and($holder->period->offboarded)->toBeTrue();
})->group('RN-20', 'RN-14');
