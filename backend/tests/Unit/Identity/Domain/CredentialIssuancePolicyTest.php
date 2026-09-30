<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exception\CredentialHolderIsOffboarded;
use App\Modules\Identity\Domain\Policy\CredentialIssuancePolicy;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/*
 * RN-14 en la emision de tarjetas: a una persona de baja no se le emite ni se le
 * reemite ninguna. La baja ya revoco las suyas (N1); emitirle otra dejaria una
 * tarjeta imprimible a nombre de quien ya no trabaja aqui.
 *
 * La suspension NO se prohibe: la especificacion no lo dice y la persona
 * suspendida vuelve. Esta prueba lo fija para que nadie lo endurezca sin
 * decidirlo.
 */

it('no deja emitir una tarjeta a una persona de baja', function (): void {
    expect(fn () => (new CredentialIssuancePolicy)->assertMayReceiveCredential(EmploymentStatus::TERMINATED))
        ->toThrow(CredentialHolderIsOffboarded::class);
})->group('RN-14', 'RF-QR-01');

it('deja emitirla a quien esta de alta o suspendido', function (EmploymentStatus $status): void {
    (new CredentialIssuancePolicy)->assertMayReceiveCredential($status);

    expect(true)->toBeTrue();
})->with([
    'de alta' => [EmploymentStatus::ACTIVE],
    'suspendido' => [EmploymentStatus::SUSPENDED],
])->group('RN-14', 'RF-QR-01');
