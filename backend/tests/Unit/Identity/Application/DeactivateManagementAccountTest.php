<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\DeactivateManagementAccountCommand;
use App\Modules\Identity\Application\Exception\ManagementSessionVanished;
use App\Modules\Identity\Application\UseCase\AccountDeactivationOutcome;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\ManagementAccountDoubles;
use Tests\Support\Time\FixedClock;

/*
 * La baja de una cuenta de gestion, sin framework y sin base de datos
 * (**RF-ID-10**, **RS-05**, **RS-06**, **RL-16**).
 *
 * Lo que vive aqui es la maquina de desenlaces del caso de uso y el orden de
 * los candados: cuando hay baja y cuando no, que se publica, a quien se le
 * cierran las sesiones, y que todo eso ocurre con la cadena de auditoria
 * tomada ANTES de bloquear la fila (ADR-010). El recorrido por HTTP y la
 * concurrencia real estan en Feature e Integration.
 */

const DEACTIVATION_TEST_ACTOR = '0199c4a1-6f2d-7b10-9e3a-000000000042';

const DEACTIVATION_TEST_SECOND_ADMIN = '0199c4a1-6f2d-7b10-9e3a-000000000043';

function bajaDeCuentaCon(ManagementAccountDoubles $doubles): DeactivateManagementAccountHandler
{
    // Quien actua tiene que existir y estar activo: el caso de uso lo relee con
    // el padron tomado. Como `rrhh`, para no contar como otra `admin`.
    if (! $doubles->accounts->has(DEACTIVATION_TEST_ACTOR)) {
        $doubles->accounts->with(DEACTIVATION_TEST_ACTOR, 'actor@hotel.example', UserRole::RRHH);
    }

    return new DeactivateManagementAccountHandler(
        $doubles->accounts,
        $doubles->tokens,
        $doubles->events,
        FixedClock::at('2026-09-22 08:15:00'),
        $doubles->ledger,
        $doubles->connection(),
        $doubles->telemetry(),
    );
}

function bajaDe(string $uuid, ?string $actor = DEACTIVATION_TEST_ACTOR): DeactivateManagementAccountCommand
{
    return new DeactivateManagementAccountCommand($uuid, 'Baja al cierre de temporada', $actor);
}

it('da de baja la cuenta, cierra sus sesiones, publica el hecho y lo cuenta', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'));

    $outcome = bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID));

    $event = $doubles->events->deactivation();

    expect($outcome)->toBe(AccountDeactivationOutcome::Deactivated)
        ->and($doubles->accounts->deactivated)->toBe([InMemoryManagementAccounts::UUID])
        ->and($doubles->tokens->revokedAccounts)->toBe([InMemoryManagementAccounts::UUID])
        ->and($event->userUuid)->toBe(InMemoryManagementAccounts::UUID)
        ->and($event->reason)->toBe('Baja al cierre de temporada')
        ->and($event->actorUuid)->toBe(DEACTIVATION_TEST_ACTOR)
        ->and($event->occurredAt()->format(DATE_ATOM))->toBe('2026-09-22T08:15:00+00:00')
        ->and($doubles->metrics->changes)->toBe(['deactivated:rrhh']);
})->group('RF-ID-10', 'RS-05', 'RL-16');

it('toma la cadena, despues el padron y despues la fila: un solo orden de candados', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'));

    bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID));

    // Con el padron tomado: primero se relee a quien actua, despues la cuenta.
    expect($doubles->journal)->toBe(['chain-lock:open', 'roster-lock', 'chain-lock:close'])
        ->and($doubles->accounts->locks)->toBe([DEACTIVATION_TEST_ACTOR, InMemoryManagementAccounts::UUID]);
})->group('RF-ID-10', 'RS-05');

it('no lleva al evento mas que el uuid, el motivo y el actor', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'));

    bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID));

    $event = $doubles->events->deactivation();

    expect(array_keys(get_object_vars($event)))->toBe(['userUuid', 'reason', 'actorUuid'])
        ->and((string) json_encode($event))->not->toContain('jefatura@hotel.example');
})->group('RS-05', 'RL-16');

it('no escribe, no revoca, no publica ni cuenta cuando la baja no ocurre', function (
    Closure $scenario,
    string $target,
    ?string $actor,
    AccountDeactivationOutcome $expected,
): void {
    /** @var InMemoryManagementAccounts $accounts */
    $accounts = $scenario();
    $doubles = new ManagementAccountDoubles($accounts);

    $outcome = bajaDeCuentaCon($doubles)->handle(bajaDe($target, $actor));

    expect($outcome)->toBe($expected)
        ->and($doubles->accounts->deactivated)->toBe([])
        ->and($doubles->tokens->revokedAccounts)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->with([
    'no existe' => [
        InMemoryManagementAccounts::empty(...),
        InMemoryManagementAccounts::UUID, DEACTIVATION_TEST_ACTOR, AccountDeactivationOutcome::NotFound,
    ],
    'ya estaba de baja' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withDeactivatedAccount('jefatura@hotel.example'),
        InMemoryManagementAccounts::UUID, DEACTIVATION_TEST_ACTOR, AccountDeactivationOutcome::AlreadyInactive,
    ],
    'la propia cuenta' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::empty()
            ->with(DEACTIVATION_TEST_ACTOR, 'yo@hotel.example', UserRole::ADMIN)
            ->with(DEACTIVATION_TEST_SECOND_ADMIN, 'otra@hotel.example', UserRole::ADMIN),
        DEACTIVATION_TEST_ACTOR, DEACTIVATION_TEST_ACTOR, AccountDeactivationOutcome::OwnAccount,
    ],
    'la ultima admin activa, desde consola' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::empty()
            ->with(DEACTIVATION_TEST_SECOND_ADMIN, 'unica@hotel.example', UserRole::ADMIN)
            ->with(DEACTIVATION_TEST_ACTOR, 'baja@hotel.example', UserRole::ADMIN, active: false),
        DEACTIVATION_TEST_SECOND_ADMIN, null, AccountDeactivationOutcome::LastActiveAdmin,
    ],
])->group('RF-ID-10', 'RS-05', 'RL-16');

it('da de baja una admin si queda otra activa', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::empty()
        ->with(DEACTIVATION_TEST_ACTOR, 'yo@hotel.example', UserRole::ADMIN)
        ->with(DEACTIVATION_TEST_SECOND_ADMIN, 'otra@hotel.example', UserRole::ADMIN));

    $outcome = bajaDeCuentaCon($doubles)->handle(bajaDe(DEACTIVATION_TEST_SECOND_ADMIN));

    expect($outcome)->toBe(AccountDeactivationOutcome::Deactivated)
        ->and($doubles->metrics->changes)->toBe(['deactivated:admin']);
})->group('RF-ID-10', 'RS-05');

it('atribuye al sistema la baja de consola', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'));

    bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID, null));

    expect($doubles->events->deactivation()->actorUuid)->toBeNull();
})->group('RL-16');

it('aborta sin escribir ni asentar si quien da de baja ya esta de baja al tomar el padron', function (): void {
    // Tiempo de comprobacion frente a tiempo de uso: la sesion se valido al
    // entrar, pero otra `admin` le dio de baja mientras tanto.
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example')
        ->with(DEACTIVATION_TEST_ACTOR, 'actor@hotel.example', UserRole::ADMIN, active: false));

    expect(fn (): AccountDeactivationOutcome => bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID)))
        ->toThrow(ManagementSessionVanished::class);

    expect($doubles->accounts->deactivated)->toBe([])
        ->and($doubles->tokens->revokedAccounts)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([])
        ->and($doubles->accounts->locks)->toBe([DEACTIVATION_TEST_ACTOR]);
})->group('RF-ID-10', 'RS-05');

it('no hace nada fuera de la transaccion', function (): void {
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'));
    $doubles->ledger->abort = true;

    expect(fn (): AccountDeactivationOutcome => bajaDeCuentaCon($doubles)->handle(bajaDe(InMemoryManagementAccounts::UUID)))
        ->toThrow(LogicException::class);

    expect($doubles->accounts->deactivated)->toBe([])
        ->and($doubles->tokens->revokedAccounts)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->group('RS-05', 'RL-16');
