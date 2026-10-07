<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\ResetManagementPasswordCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\UseCase\ManagementPasswordResetOutcome;
use App\Modules\Identity\Application\UseCase\ManagementPasswordResetStatus;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Identity\FixedTemporaryPasswordGenerator;
use Tests\Support\Identity\FixedTwoFactorAuthenticator;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\ManagementAccountDoubles;
use Tests\Support\Time\FixedClock;

/*
 * El restablecimiento de la contrasena de otra cuenta (RF-ID-10, RS-06,
 * RL-16), sin framework ni base de datos.
 *
 * Lo que se afirma es del caso de uso (doc 02 §9.5): que la contrasena nueva es
 * TEMPORAL y caduca, que se genera y se hashea FUERA del candado de la cadena,
 * que solo actua sobre una cuenta activa ajena, que quien actua se reautentica
 * y que el evento lleva el motivo y nunca la contrasena.
 */

const PASSWORD_RESET_TEST_ACTOR = '0199c4a1-6f2d-7b10-9e3a-000000000042';

/**
 * @return array{0: ManagementAccountDoubles, 1: ResetManagementPasswordHandler}
 */
function restablecimientoCon(?InMemoryManagementAccounts $accounts = null): array
{
    $doubles = new ManagementAccountDoubles($accounts ?? InMemoryManagementAccounts::empty()
        ->with(InMemoryManagementAccounts::UUID, 'jefatura@hotel.example', UserRole::RRHH)
        ->with(PASSWORD_RESET_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true));
    $doubles->secrets->active[PASSWORD_RESET_TEST_ACTOR] = 'SECRETO';

    return [$doubles, new ResetManagementPasswordHandler(
        $doubles->accounts,
        $doubles->tokens,
        $doubles->generator(),
        $doubles->hasher,
        $doubles->settings(),
        $doubles->reauthentication(),
        $doubles->events,
        FixedClock::at('2026-09-22 08:15:00'),
        $doubles->ledger,
        $doubles->telemetry(),
    )];
}

function restablecer(string $uuid, ?string $actor = PASSWORD_RESET_TEST_ACTOR, string $code = FixedTwoFactorAuthenticator::VALID_CODE): ResetManagementPasswordCommand
{
    return new ResetManagementPasswordCommand($uuid, 'Olvido tras las vacaciones', $actor, new ActorProof($code, null));
}

it('emite una temporal que caduca a las 72 horas, cierra las sesiones y publica el motivo', function (): void {
    [$doubles, $handler] = restablecimientoCon();

    $outcome = $handler->handle(restablecer(InMemoryManagementAccounts::UUID));

    $event = $doubles->events->passwordReset();

    expect($outcome->status)->toBe(ManagementPasswordResetStatus::Reset)
        ->and($outcome->password()->plain)->toBe(FixedTemporaryPasswordGenerator::PASSWORD)
        ->and($outcome->expiresAt()->format(DATE_ATOM))->toBe('2026-09-25T08:15:00+00:00')
        ->and($doubles->accounts->passwords[InMemoryManagementAccounts::UUID]['hash'])
        ->toBe('hash:'.FixedTemporaryPasswordGenerator::PASSWORD)
        ->and($doubles->accounts->passwords[InMemoryManagementAccounts::UUID]['expires']?->format(DATE_ATOM))
        ->toBe('2026-09-25T08:15:00+00:00')
        ->and($doubles->tokens->revokedAccounts)->toBe([InMemoryManagementAccounts::UUID])
        ->and($event->reason)->toBe('Olvido tras las vacaciones')
        ->and($event->actorUuid)->toBe(PASSWORD_RESET_TEST_ACTOR)
        ->and(array_keys(get_object_vars($event)))->toBe(['userUuid', 'reason', 'actorUuid'])
        ->and((string) json_encode($event))->not->toContain(FixedTemporaryPasswordGenerator::PASSWORD)
        ->and($doubles->metrics->changes)->toBe(['password_reset:rrhh']);
})->group('RF-ID-10', 'RS-06', 'RL-16');

it('hashea la temporal antes de tomar el candado de la cadena', function (): void {
    [$doubles, $handler] = restablecimientoCon();

    $handler->handle(restablecer(InMemoryManagementAccounts::UUID));

    expect($doubles->journal)->toBe(['hash', 'chain-lock:open', 'chain-lock:close']);
})->group('RF-ID-10', 'RS-06');

it('no restablece nada sobre una cuenta inexistente, de baja o propia', function (
    string $target,
    Closure $scenario,
    ManagementPasswordResetStatus $expected,
): void {
    [$doubles, $handler] = restablecimientoCon($scenario());
    $doubles->secrets->active[PASSWORD_RESET_TEST_ACTOR] = 'SECRETO';

    $outcome = $handler->handle(restablecer($target));

    expect($outcome->status)->toBe($expected)
        ->and($doubles->accounts->passwords)->toBe([])
        ->and($doubles->tokens->revokedAccounts)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->with([
    'no existe' => [
        '0199c4a1-6f2d-7b10-9e3a-0000000000ff',
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::empty()
            ->with(PASSWORD_RESET_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true),
        ManagementPasswordResetStatus::NotFound,
    ],
    'de baja' => [
        InMemoryManagementAccounts::UUID,
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::empty()
            ->with(InMemoryManagementAccounts::UUID, 'baja@hotel.example', UserRole::RRHH, active: false)
            ->with(PASSWORD_RESET_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true),
        ManagementPasswordResetStatus::NotFound,
    ],
    'la propia' => [
        PASSWORD_RESET_TEST_ACTOR,
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::empty()
            ->with(PASSWORD_RESET_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true),
        ManagementPasswordResetStatus::OwnAccount,
    ],
])->group('RF-ID-10', 'RS-06', 'RS-03');

it('exige el codigo del autenticador de quien actua y lo cuenta en su contador de segundo factor', function (): void {
    [$doubles, $handler] = restablecimientoCon();

    expect(fn (): ManagementPasswordResetOutcome => $handler->handle(restablecer(InMemoryManagementAccounts::UUID, code: '000000')))
        ->toThrow(ActorReauthenticationFailed::class);

    expect($doubles->attempts->failures)->toBe(['2fa|'.PASSWORD_RESET_TEST_ACTOR => 1])
        ->and($doubles->accounts->passwords)->toBe([])
        ->and($doubles->events->published)->toBe([]);
})->group('RF-ID-10', 'RS-06');

it('no acepta dos veces el mismo codigo en su franja', function (): void {
    [$doubles, $handler] = restablecimientoCon();
    $doubles->secrets->slices[PASSWORD_RESET_TEST_ACTOR] = 7;

    expect(fn (): ManagementPasswordResetOutcome => $handler->handle(restablecer(InMemoryManagementAccounts::UUID)))
        ->toThrow(ActorReauthenticationFailed::class);
})->group('RF-ID-10', 'RS-06');

it('gasta el codigo: la segunda peticion con el mismo codigo falla y cuenta como intento', function (): void {
    [$doubles, $handler] = restablecimientoCon();

    expect($handler->handle(restablecer(InMemoryManagementAccounts::UUID))->status)->toBe(ManagementPasswordResetStatus::Reset);

    expect(fn (): ManagementPasswordResetOutcome => $handler->handle(restablecer(InMemoryManagementAccounts::UUID)))
        ->toThrow(ActorReauthenticationFailed::class);

    expect($doubles->attempts->failures)->toBe(['2fa|'.PASSWORD_RESET_TEST_ACTOR => 1]);
})->group('RF-ID-10', 'RS-06');

it('responde con el bloqueo abierto sin mirar el codigo', function (): void {
    [$doubles, $handler] = restablecimientoCon();
    $doubles->attempts->failures['2fa|'.PASSWORD_RESET_TEST_ACTOR] = 3;

    expect(fn (): ManagementPasswordResetOutcome => $handler->handle(restablecer(InMemoryManagementAccounts::UUID)))
        ->toThrow(AccountTemporarilyLocked::class);

    expect($doubles->journal)->toBe([]);
})->group('RF-ID-10', 'RF-ID-01');

it('atribuye al sistema el restablecimiento de consola, sin reautenticacion', function (): void {
    [$doubles, $handler] = restablecimientoCon();

    $handler->handle(new ResetManagementPasswordCommand(InMemoryManagementAccounts::UUID, 'Sin motivo declarado'));

    expect($doubles->events->passwordReset()->actorUuid)->toBeNull();
})->group('RL-16');
