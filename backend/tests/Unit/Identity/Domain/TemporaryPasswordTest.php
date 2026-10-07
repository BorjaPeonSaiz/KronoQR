<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Policy\DeactivationVerdict;
use App\Modules\Identity\Domain\Policy\ManagementAccountDeactivationGuard;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use App\Modules\Identity\Domain\ValueObject\TemporaryPasswordLifetime;

/*
 * El dominio de las cuentas de gestion desde el panel (RF-ID-10, ADR-051): de
 * quien es la contrasena, cuanto vive una temporal y la invariante de la baja.
 *
 * Sin reloj dentro (regla dura 2): el instante entra como parametro, y por eso
 * se puede probar la frontera exacta de la caducidad y un cambio de hora sin
 * esperar a ninguno.
 */

const TEMPORARY_PASSWORD_TEST_EXPIRES = '2026-10-10T08:00:00+00:00';

const TEMPORARY_PASSWORD_TEST_ADMIN = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

const TEMPORARY_PASSWORD_TEST_OTHER = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91';

it('trata como propia la contrasena sin caducidad de temporal', function (): void {
    $status = PasswordStatus::of(null, new DateTimeImmutable('2026-10-07T08:00:00+00:00'));

    expect($status)->toBe(PasswordStatus::Own)
        ->and($status->requiresChange())->toBeFalse();
})->group('RF-ID-10');

it('distingue la temporal vigente, la caducada en el instante exacto y la caducada despues', function (
    string $now,
    PasswordStatus $expected,
): void {
    // La frontera exacta YA esta caducada: «desde este instante ya no sirve
    // para entrar», como dice `expires_at` en el contrato.
    expect(PasswordStatus::of(new DateTimeImmutable(TEMPORARY_PASSWORD_TEST_EXPIRES), new DateTimeImmutable($now)))
        ->toBe($expected);
})->with([
    'un segundo antes' => ['2026-10-10T07:59:59+00:00', PasswordStatus::Temporary],
    'en el instante exacto' => ['2026-10-10T08:00:00+00:00', PasswordStatus::TemporaryExpired],
    'un segundo despues' => ['2026-10-10T08:00:01+00:00', PasswordStatus::TemporaryExpired],
    'mismo instante en otra zona' => ['2026-10-10T10:00:00+02:00', PasswordStatus::TemporaryExpired],
])->group('RF-ID-10', 'RS-03');

it('exige cambiarla mientras sea temporal, tambien caducada', function (): void {
    expect(PasswordStatus::Temporary->requiresChange())->toBeTrue()
        ->and(PasswordStatus::TemporaryExpired->requiresChange())->toBeTrue();
})->group('RF-ID-10');

it('publica los tres valores del contrato', function (): void {
    expect(array_map(static fn (PasswordStatus $status): string => $status->value, PasswordStatus::cases()))
        ->toBe(['own', 'temporary', 'temporary_expired']);
})->group('RF-ID-10', 'RQ-06');

it('rechaza una vida fuera de 1 a 168 horas', function (int $hours): void {
    expect(static fn (): TemporaryPasswordLifetime => new TemporaryPasswordLifetime($hours))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, 169, 720])->group('RF-ID-10');

it('acepta los dos extremos de la vida', function (int $hours): void {
    expect((new TemporaryPasswordLifetime($hours))->hours)->toBe($hours);
})->with([1, 168])->group('RF-ID-10');

it('caduca exactamente N horas transcurridas despues, tambien cruzando un cambio de hora', function (): void {
    // 72 h desde el viernes anterior al cambio de hora de octubre en Madrid:
    // en reloj de pared serian 71, pero lo que caduca es el tiempo transcurrido.
    $issuedAt = new DateTimeImmutable('2026-10-23T10:00:00', new DateTimeZone('Europe/Madrid'));

    $expiresAt = new TemporaryPasswordLifetime(72)->expiresAt($issuedAt);

    expect($expiresAt->getTimestamp() - $issuedAt->getTimestamp())->toBe(72 * 3600)
        ->and($expiresAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM))->toBe('2026-10-26T08:00:00+00:00');
})->group('RF-ID-10');

it('no deja ver la temporal en un volcado ni serializarla', function (): void {
    $password = new TemporaryPassword('Kd2pQ9vLmN4tZbYc#F7w', 'hash');

    expect(print_r($password, true))->not->toContain('Kd2pQ9vLmN4tZbYc#F7w')
        ->and($password->__debugInfo())->toBe(['plain' => '***', 'hash' => '***'])
        ->and(static fn (): string => serialize($password))->toThrow(LogicException::class);
})->group('RF-ID-10', 'RS-06');

it('no admite una temporal sin valor o sin hash', function (): void {
    expect(static fn (): TemporaryPassword => new TemporaryPassword('', 'hash'))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): TemporaryPassword => new TemporaryPassword('x', ''))->toThrow(InvalidArgumentException::class);
})->group('RF-ID-10');

it('decide la baja segun la invariante de la instalacion', function (
    ?string $actor,
    string $target,
    bool $targetIsActiveAdmin,
    int $activeAdmins,
    DeactivationVerdict $expected,
): void {
    expect(ManagementAccountDeactivationGuard::decide($actor, $target, $targetIsActiveAdmin, $activeAdmins))
        ->toBe($expected);
})->with([
    'la propia cuenta' => [TEMPORARY_PASSWORD_TEST_ADMIN, TEMPORARY_PASSWORD_TEST_ADMIN, true, 3, DeactivationVerdict::OwnAccount],
    'la ultima admin activa' => [TEMPORARY_PASSWORD_TEST_ADMIN, TEMPORARY_PASSWORD_TEST_OTHER, true, 1, DeactivationVerdict::LastActiveAdmin],
    'una admin con otra activa' => [TEMPORARY_PASSWORD_TEST_ADMIN, TEMPORARY_PASSWORD_TEST_OTHER, true, 2, DeactivationVerdict::Allowed],
    'una no admin con una sola admin' => [TEMPORARY_PASSWORD_TEST_ADMIN, TEMPORARY_PASSWORD_TEST_OTHER, false, 1, DeactivationVerdict::Allowed],
    'la consola, sin actor, nunca es la propia' => [null, TEMPORARY_PASSWORD_TEST_ADMIN, false, 1, DeactivationVerdict::Allowed],
    'la consola tampoco deja sin admin' => [null, TEMPORARY_PASSWORD_TEST_ADMIN, true, 1, DeactivationVerdict::LastActiveAdmin],
    'cero admins contadas (incoherencia) no deja dar de baja' => [null, TEMPORARY_PASSWORD_TEST_OTHER, true, 0, DeactivationVerdict::LastActiveAdmin],
])->group('RF-ID-10', 'RS-05');
