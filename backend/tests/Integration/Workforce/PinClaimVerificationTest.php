<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RN-19 en el verificador del PIN (ADR-043): cuando el rechazo lleva al dueño
 * del codigo y cuando no.
 *
 * El claim solo existe si el codigo es de alguien que PUEDE FICHAR y el PIN no
 * verifico. Nunca con codigo inexistente, baja o suspendido, y nunca en el
 * acierto. En todos los casos `employeeUuid()` del rechazo sigue siendo nulo:
 * lo que cambia es solo lo que el quiosco escribe en `scan_events`. Las pruebas
 * de tiempo constante (`PinRejectionSymmetryTest`, `ConstantTimeRejectionTest`)
 * siguen siendo las que vigilan que eso no mueva el tiempo.
 */

uses(RefreshDatabase::class);

const PIN_CLAIM_VERIFICATION_GOOD = '481902';

const PIN_CLAIM_VERIFICATION_BAD = '481903';

/**
 * @return array{uuid: string, code: string}
 */
function pinClaimEmployee(string $status = 'active', bool $withPin = true): array
{
    $site = WorkforceFixtures::site('Hotel del claim', 'Europe/Madrid');
    $uuid = WorkforceFixtures::employee($site, null, $status);

    if ($withPin) {
        EmployeePins::issue($uuid, PIN_CLAIM_VERIFICATION_GOOD);
    }

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

function pinClaimVerifier(): EmployeePinVerifier
{
    return app(EmployeePinVerifier::class);
}

beforeEach(function (): void {
    app(Cache::class)->clear();

    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);

    FrozenTime::at('2026-03-14 06:00:00');
    app()->forgetInstance(PinAttempts::class);
});

it('anota al dueño sin bloqueo en el primer PIN erroneo de alguien activo', function (): void {
    $employee = pinClaimEmployee();

    $verification = pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_BAD, PinOrigin::KIOSK);

    expect($verification->employeeUuid())->toBeNull()
        ->and($verification->isVerified())->toBeFalse()
        ->and($verification->claim()?->claimantUuid)->toBe($employee['uuid'])
        ->and($verification->claim()?->lockout)->toBeFalse();
})->group('RN-19', 'RF-AT-11');

it('marca el bloqueo en el fallo que lo abre y en el intento bloqueado', function (): void {
    $employee = pinClaimEmployee();

    pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_BAD, PinOrigin::KIOSK);
    pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_BAD, PinOrigin::KIOSK);

    $third = pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_BAD, PinOrigin::KIOSK);

    expect($third->isLocked())->toBeFalse()
        ->and($third->claim()?->claimantUuid)->toBe($employee['uuid'])
        ->and($third->claim()?->lockout)->toBeTrue();

    // Con el bloqueo puesto, ni el PIN bueno verifica: sale bloqueado y con
    // claim, que es justo el caso de SC7-02.
    $locked = pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_GOOD, PinOrigin::KIOSK);

    expect($locked->isLocked())->toBeTrue()
        ->and($locked->employeeUuid())->toBeNull()
        ->and($locked->claim()?->claimantUuid)->toBe($employee['uuid'])
        ->and($locked->claim()?->lockout)->toBeTrue();
})->group('RN-19', 'RS-12');

it('anota al dueño activo aunque no tenga PIN emitido', function (): void {
    $employee = pinClaimEmployee(withPin: false);

    $verification = pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_GOOD, PinOrigin::KIOSK);

    expect($verification->isVerified())->toBeFalse()
        ->and($verification->claim()?->claimantUuid)->toBe($employee['uuid']);
})->group('RN-19', 'RF-AT-11');

it('no anota a nadie con un codigo inexistente, una baja o un suspendido', function (?string $status): void {
    $code = 'ENOEXISTE';

    if ($status !== null) {
        $code = pinClaimEmployee($status)['code'];
    }

    $verification = pinClaimVerifier()->verify($code, PIN_CLAIM_VERIFICATION_BAD, PinOrigin::KIOSK);

    expect($verification->isVerified())->toBeFalse()
        ->and($verification->claim())->toBeNull();
})->with([
    'codigo inexistente' => [null],
    'baja' => ['terminated'],
    'suspendido' => ['suspended'],
])->group('RN-19', 'RN-14', 'RS-03');

it('no anota a nadie en el acierto', function (): void {
    $employee = pinClaimEmployee();

    $verification = pinClaimVerifier()->verify($employee['code'], PIN_CLAIM_VERIFICATION_GOOD, PinOrigin::KIOSK);

    expect($verification->employeeUuid())->toBe($employee['uuid'])
        ->and($verification->claim())->toBeNull();
})->group('RN-19', 'RF-AT-11');
