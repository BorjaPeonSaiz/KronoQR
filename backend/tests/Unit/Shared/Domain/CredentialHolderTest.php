<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\CredentialHolder;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;

/*
 * RN-20 (ADR-047): el titular de una tarjeta autentica retirada viaja del
 * resolutor a la fila de `scan_events` **sin dejar de ser el mismo rechazo**
 * hacia fuera. Con o sin titular, `employeeUuid()` es nulo: nada que construya
 * una respuesta puede distinguir una tarjeta revocada de una inexistente.
 */

const CREDENTIAL_HOLDER_TEST_UUID = '0199f0c2-0000-7000-8000-0000000000aa';

function emitidaEl(string $instant, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone($zone));
}

it('no construye un titular sin UUID', function (): void {
    expect(fn (): CredentialHolder => CredentialHolder::of('', emitidaEl('2026-08-01 09:00:00'), null))
        ->toThrow(InvalidArgumentException::class);
})->group('RN-20');

it('guarda la emision y la retirada en UTC', function (): void {
    $holder = CredentialHolder::of(
        CREDENTIAL_HOLDER_TEST_UUID,
        emitidaEl('2026-08-01 11:00:00', 'Europe/Madrid'),
        emitidaEl('2026-08-15 08:00:00', 'Europe/Madrid'),
    );

    expect($holder->issuedAt->format('Y-m-d H:i:s e'))->toBe('2026-08-01 09:00:00 UTC')
        ->and($holder->withdrawnAt?->format('Y-m-d H:i:s e'))->toBe('2026-08-15 06:00:00 UTC')
        ->and(CredentialHolder::of(CREDENTIAL_HOLDER_TEST_UUID, emitidaEl('2026-08-01 09:00:00'), null)->withdrawnAt)->toBeNull();
})->group('RN-20');

it('traduce el titular a una resolucion que hacia fuera es el mismo rechazo', function (CredentialRejectionReason $reason): void {
    $holder = CredentialHolder::of(CREDENTIAL_HOLDER_TEST_UUID, emitidaEl('2026-08-01 09:00:00'), null);
    $resolution = CredentialResolution::rejectedWithHolder($reason, $holder);

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->employeeUuid())->toBeNull()
        ->and($resolution->rejectionReason())->toBe($reason)
        ->and($resolution->holder())->toBe($holder)
        ->and($resolution->pinClaim())->toBeNull()
        // Las fabricas de siempre no llevan titular.
        ->and(CredentialResolution::rejected($reason)->holder())->toBeNull()
        ->and(CredentialResolution::resolved(CREDENTIAL_HOLDER_TEST_UUID)->holder())->toBeNull();
})->with([
    'revocada' => [CredentialRejectionReason::REVOKED],
    'desconocida' => [CredentialRejectionReason::UNKNOWN],
])->group('RN-20', 'RN-14', 'RS-03');

it('lleva la emision de la credencial que resolvio, solo para atribuir avisos', function (): void {
    // RN-22: la emision acota la revision del aviso de una tarjeta vigente.
    $emision = emitidaEl('2026-08-01 09:00:00');

    expect(CredentialResolution::resolved(CREDENTIAL_HOLDER_TEST_UUID, $emision)->issuedAt())->toBe($emision)
        ->and(CredentialResolution::resolved(CREDENTIAL_HOLDER_TEST_UUID)->issuedAt())->toBeNull()
        ->and(CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN)->issuedAt())->toBeNull();
})->group('RN-22', 'RS-03');
