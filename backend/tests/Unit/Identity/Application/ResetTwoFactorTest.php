<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\ResetManagementTwoFactorCommand;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\TwoFactorResetOutcome;
use App\Modules\Identity\Domain\Event\TwoFactorReset;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Identity\FixedTwoFactorAuthenticator;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\ManagementAccountDoubles;
use Tests\Support\Time\FixedClock;

/*
 * La retirada del segundo factor de otra cuenta (RF-ID-10, RS-06), sin
 * framework ni base de datos. Lo que no retira nada no deja asiento.
 */

const TWO_FACTOR_RESET_TEST_ACTOR = '0199c4a1-6f2d-7b10-9e3a-000000000042';

/**
 * @return array{0: ManagementAccountDoubles, 1: ResetTwoFactorHandler}
 */
function retiradaDeSegundoFactorCon(bool $targetHasTwoFactor = true): array
{
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::empty()
        ->with(InMemoryManagementAccounts::UUID, 'rrhh@hotel.example', UserRole::RRHH, twoFactor: $targetHasTwoFactor)
        ->with(TWO_FACTOR_RESET_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true));
    $doubles->secrets->active[TWO_FACTOR_RESET_TEST_ACTOR] = 'SECRETO-ADMIN';
    $doubles->secrets->active[InMemoryManagementAccounts::UUID] = 'SECRETO-RRHH';

    return [$doubles, new ResetTwoFactorHandler(
        $doubles->accounts,
        $doubles->secrets,
        $doubles->tokens,
        $doubles->reauthentication(),
        $doubles->events,
        FixedClock::at('2026-10-07 09:00:00'),
        $doubles->ledger,
        $doubles->telemetry(),
    )];
}

function retirada(string $uuid): ResetManagementTwoFactorCommand
{
    return new ResetManagementTwoFactorCommand($uuid, 'Telefono extraviado', TWO_FACTOR_RESET_TEST_ACTOR, new ActorProof(FixedTwoFactorAuthenticator::VALID_CODE, null));
}

it('retira el secreto, cierra todas las sesiones y publica quien y por que', function (): void {
    [$doubles, $handler] = retiradaDeSegundoFactorCon();

    $outcome = $handler->handle(retirada(InMemoryManagementAccounts::UUID));

    $event = $doubles->events->published[0];

    expect($outcome)->toBe(TwoFactorResetOutcome::Reset)
        ->and($doubles->secrets->activeSecretFor(InMemoryManagementAccounts::UUID))->toBeNull()
        ->and($doubles->tokens->revokedAccounts)->toBe([InMemoryManagementAccounts::UUID])
        ->and($event)->toBeInstanceOf(TwoFactorReset::class)
        ->and($doubles->metrics->changes)->toBe(['two_factor_reset:rrhh'])
        ->and($doubles->journal)->toBe(['chain-lock:open', 'chain-lock:close'])
        ->and($doubles->accounts->locks)->toBe([TWO_FACTOR_RESET_TEST_ACTOR, InMemoryManagementAccounts::UUID]);
})->group('RF-ID-10', 'RS-06');

it('no escribe nada sin segundo factor confirmado, sobre la propia o sobre una cuenta que no existe', function (
    string $target,
    bool $targetHasTwoFactor,
    TwoFactorResetOutcome $expected,
): void {
    [$doubles, $handler] = retiradaDeSegundoFactorCon($targetHasTwoFactor);

    expect($handler->handle(retirada($target)))->toBe($expected)
        ->and($doubles->tokens->revokedAccounts)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->with([
    'sin segundo factor' => [InMemoryManagementAccounts::UUID, false, TwoFactorResetOutcome::NotEnrolled],
    'la propia' => [TWO_FACTOR_RESET_TEST_ACTOR, true, TwoFactorResetOutcome::OwnAccount],
    'no existe' => ['0199c4a1-6f2d-7b10-9e3a-0000000000ff', true, TwoFactorResetOutcome::NotFound],
])->group('RF-ID-10', 'RS-06', 'RS-03');
