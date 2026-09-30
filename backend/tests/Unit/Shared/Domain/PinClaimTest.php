<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\PinClaim;
use App\Modules\Shared\Domain\ValueObject\PinVerification;

/*
 * RN-19 y ADR-043: el dueño del codigo de un PIN rechazado viaja del verificador
 * a la fila de `scan_events` **sin dejar de ser el mismo rechazo** hacia fuera.
 *
 * Lo que fijan estas pruebas es la mitad de RS-03 que vive en los tipos: con o
 * sin claim, `employeeUuid()` es nulo y nada que construya una respuesta puede
 * distinguir un PIN erroneo de un codigo que no existe.
 */

const PIN_CLAIM_TEST_UUID = '0199f0c2-0000-7000-8000-000000000001';

function pinClaimScanRecord(ScanOrigin $origin, ScanResult $result, ?string $employeeUuid, ?PinClaim $claim): ScanRecord
{
    return new ScanRecord(
        scanId: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        deviceId: 1,
        employeeUuid: $employeeUuid,
        occurredAt: new DateTimeImmutable('2026-03-14 07:00:00', new DateTimeZone('UTC')),
        recordedAt: new DateTimeImmutable('2026-03-14 07:00:00', new DateTimeZone('UTC')),
        origin: $origin,
        intent: ScanIntent::AUTO,
        result: $result,
        pinClaim: $claim,
    );
}

it('no construye un claim sin dueño', function (): void {
    expect(fn (): PinClaim => PinClaim::of('', false))->toThrow(InvalidArgumentException::class);
})->group('RN-19');

it('deja el PIN verificado sin claim', function (): void {
    expect(PinVerification::verified(PIN_CLAIM_TEST_UUID)->claim())->toBeNull();
})->group('RN-19', 'RF-AT-11');

it('lleva el claim en el rechazo y en el bloqueo sin dejar de ser un rechazo', function (PinVerification $verification): void {
    expect($verification->employeeUuid())->toBeNull()
        ->and($verification->isVerified())->toBeFalse()
        ->and($verification->claim()?->claimantUuid)->toBe(PIN_CLAIM_TEST_UUID);
})->with([
    'PIN erroneo' => fn (): PinVerification => PinVerification::rejected(PinClaim::of(PIN_CLAIM_TEST_UUID, false)),
    'fallo que abre el bloqueo' => fn (): PinVerification => PinVerification::rejected(PinClaim::of(PIN_CLAIM_TEST_UUID, true)),
    'bloqueo activo' => fn (): PinVerification => PinVerification::locked(300, PinClaim::of(PIN_CLAIM_TEST_UUID, true)),
])->group('RN-19', 'RS-03', 'RS-12');

it('sigue admitiendo el rechazo y el bloqueo sin claim', function (): void {
    expect(PinVerification::rejected()->claim())->toBeNull()
        ->and(PinVerification::locked(60)->claim())->toBeNull()
        ->and(PinVerification::locked(60)->isLocked())->toBeTrue();
})->group('RN-19', 'RS-12');

it('no deja un bloqueo con un claim que no marca el bloqueo', function (): void {
    expect(fn (): PinVerification => PinVerification::locked(300, PinClaim::of(PIN_CLAIM_TEST_UUID, false)))
        ->toThrow(InvalidArgumentException::class);
})->group('RN-19', 'RS-12');

it('traduce el claim a una resolucion que hacia fuera es el rechazo UNKNOWN', function (): void {
    $claim = PinClaim::of(PIN_CLAIM_TEST_UUID, true);
    $resolution = CredentialResolution::rejectedWithPinClaim($claim);

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->employeeUuid())->toBeNull()
        ->and($resolution->rejectionReason())->toBe(CredentialRejectionReason::UNKNOWN)
        ->and($resolution->pinClaim())->toBe($claim)
        // Las dos fabricas de siempre no llevan claim.
        ->and(CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN)->pinClaim())->toBeNull()
        ->and(CredentialResolution::resolved(PIN_CLAIM_TEST_UUID)->pinClaim())->toBeNull();
})->group('RN-19', 'RS-03');

it('solo admite el claim en un fichaje por PIN rechazado y sin empleado', function (ScanOrigin $origin, ScanResult $result, ?string $employeeUuid): void {
    // El espejo en PHP del `CHECK scan_events_chk_pin_claim`.
    expect(fn (): ScanRecord => pinClaimScanRecord($origin, $result, $employeeUuid, PinClaim::of(PIN_CLAIM_TEST_UUID, false)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'escaneo de tarjeta' => [ScanOrigin::QR_KIOSK, ScanResult::REJECTED_UNKNOWN, null],
    'fichaje aceptado' => [ScanOrigin::PIN_KIOSK, ScanResult::CLOCK_IN, null],
    'otro rechazo' => [ScanOrigin::PIN_KIOSK, ScanResult::REJECTED_REVOKED, null],
    'con empleado resuelto' => [ScanOrigin::PIN_KIOSK, ScanResult::REJECTED_UNKNOWN, PIN_CLAIM_TEST_UUID],
])->group('RN-19');

it('construye la fila valida con claim y cualquier fila sin el', function (): void {
    $claim = PinClaim::of(PIN_CLAIM_TEST_UUID, true);

    expect(pinClaimScanRecord(ScanOrigin::PIN_KIOSK, ScanResult::REJECTED_UNKNOWN, null, $claim)->pinClaim)->toBe($claim)
        ->and(pinClaimScanRecord(ScanOrigin::QR_KIOSK, ScanResult::CLOCK_IN, PIN_CLAIM_TEST_UUID, null)->pinClaim)->toBeNull();
})->group('RN-19');
