<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\ChangeOwnPasswordCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordHandler;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordOutcome;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Psr\Log\NullLogger;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\InMemoryUserAccounts;
use Tests\Support\Identity\ManagementAccountDoubles;
use Tests\Support\Time\FixedClock;

/*
 * El cambio de la contrasena propia (RF-ID-10, RF-ID-01), sin framework ni base
 * de datos: la salida de la sesion de contrasena temporal.
 *
 * Se afirma lo que es del caso de uso: la actual se compara FUERA del candado,
 * un fallo cuenta en un contador propio y al bloquear se revoca el token con el
 * que se probo, la escritura es condicionada (hash leido y token vivo), y al
 * cambiarla el token actual recibe los ambitos del rol mientras las demas
 * sesiones se cierran.
 */

const CHANGE_OWN_PASSWORD_TEST_TOKEN = 41;

const CHANGE_OWN_PASSWORD_TEST_CURRENT = 'Contrasena-Actual-1!';

/**
 * @return array{0: ManagementAccountDoubles, 1: ChangeOwnPasswordHandler, 2: InMemoryUserAccounts}
 */
function cambioPropioCon(PasswordStatus $status = PasswordStatus::Temporary): array
{
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::withActiveAccount('rrhh@hotel.example'));
    $users = new InMemoryUserAccounts;
    $users->users[InMemoryManagementAccounts::UUID] = new AuthenticatedUser(
        uuid: InMemoryManagementAccounts::UUID,
        name: 'Direccion RRHH',
        email: 'rrhh@hotel.example',
        locale: 'es',
        roles: [UserRole::RRHH],
        abilities: [TokenAbility::EMPLOYEES_ALL, TokenAbility::ATTENDANCE_READ],
        scope: AccessScope::unrestricted(),
        passwordStatus: $status,
    );

    return [$doubles, new ChangeOwnPasswordHandler(
        $users,
        $doubles->accounts,
        $doubles->hasher,
        $doubles->attempts,
        $doubles->tokens,
        $doubles->authJournal,
        $doubles->events,
        FixedClock::at('2026-10-07 09:00:00'),
        $doubles->ledger,
        $doubles->telemetry(),
        new NullLogger,
    ), $users];
}

function cambio(string $current = CHANGE_OWN_PASSWORD_TEST_CURRENT, string $new = 'Una-Contrasena-Nueva-2!'): ChangeOwnPasswordCommand
{
    return new ChangeOwnPasswordCommand(InMemoryManagementAccounts::UUID, CHANGE_OWN_PASSWORD_TEST_TOKEN, $current, $new);
}

it('cambia la contrasena, cierra las demas sesiones y da a esta los ambitos del rol', function (): void {
    [$doubles, $handler] = cambioPropioCon();

    $outcome = $handler->handle(cambio());

    expect($outcome)->toBe(ChangeOwnPasswordOutcome::Changed)
        ->and($doubles->accounts->passwords[InMemoryManagementAccounts::UUID])
        ->toBe(['hash' => 'hash:Una-Contrasena-Nueva-2!', 'expires' => null])
        ->and($doubles->tokens->revokedAllExcept)->toBe([[InMemoryManagementAccounts::UUID, CHANGE_OWN_PASSWORD_TEST_TOKEN]])
        ->and($doubles->tokens->promoted)
        ->toBe([[InMemoryManagementAccounts::UUID, CHANGE_OWN_PASSWORD_TEST_TOKEN, ['employees:*', 'attendance:read']]])
        ->and($doubles->events->published)->toHaveCount(1)
        ->and($doubles->attempts->cleared)->toBe(['password-change|'.InMemoryManagementAccounts::UUID])
        ->and($doubles->metrics->changes)->toBe(['password_changed:rrhh']);
})->group('RF-ID-10', 'RS-06');

it('compara y hashea fuera del candado de la cadena', function (): void {
    [$doubles, $handler] = cambioPropioCon();

    $handler->handle(cambio());

    expect($doubles->journal)->toBe(['matches', 'hash', 'chain-lock:open', 'chain-lock:close']);
})->group('RF-ID-10');

it('con la actual erronea no escribe nada y cuenta en su contador propio', function (): void {
    [$doubles, $handler] = cambioPropioCon();

    $outcome = $handler->handle(cambio('Otra-Cosa-1!'));

    expect($outcome)->toBe(ChangeOwnPasswordOutcome::WrongCurrentPassword)
        ->and($doubles->attempts->failures)->toBe(['password-change|'.InMemoryManagementAccounts::UUID => 1])
        ->and($doubles->accounts->passwords)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->tokens->revokedTokens)->toBe([]);
})->group('RF-ID-10', 'RF-ID-01');

it('al abrirse el bloqueo deja el asiento del bloqueo y revoca el token con el que se probo', function (): void {
    [$doubles, $handler] = cambioPropioCon();
    $doubles->attempts->failures['password-change|'.InMemoryManagementAccounts::UUID] = 2;

    expect(fn (): ChangeOwnPasswordOutcome => $handler->handle(cambio('Otra-Cosa-1!')))
        ->toThrow(AccountTemporarilyLocked::class);

    expect($doubles->authJournal->lockouts)->toBe([InMemoryManagementAccounts::UUID])
        ->and($doubles->tokens->revokedTokens)->toBe([CHANGE_OWN_PASSWORD_TEST_TOKEN]);
})->group('RF-ID-10', 'RF-ID-01');

it('rechaza una nueva igual a la actual sin escribir', function (): void {
    [$doubles, $handler] = cambioPropioCon();

    expect($handler->handle(cambio(new: CHANGE_OWN_PASSWORD_TEST_CURRENT)))->toBe(ChangeOwnPasswordOutcome::SameAsCurrent)
        ->and($doubles->accounts->passwords)->toBe([]);
})->group('RF-ID-10', 'RF-ID-01');

it('no acepta como actual una temporal caducada', function (): void {
    [$doubles, $handler] = cambioPropioCon(PasswordStatus::TemporaryExpired);

    expect($handler->handle(cambio()))->toBe(ChangeOwnPasswordOutcome::SessionGone)
        ->and($doubles->accounts->passwords)->toBe([]);
})->group('RF-ID-10', 'RS-03');

it('no pisa un restablecimiento cruzado: el hash leido tiene que seguir vigente', function (): void {
    [$doubles, , $users] = cambioPropioCon();

    // El `admin` restablece entre la comparacion y la escritura: el candado de
    // la cadena llega cuando el hash ya no es el que se comparo.
    $racing = new readonly class($doubles) implements SerializedLedgerWrite
    {
        public function __construct(private ManagementAccountDoubles $doubles) {}

        public function withChainLock(callable $work): mixed
        {
            $this->doubles->accounts->overwriteHash(InMemoryManagementAccounts::UUID, 'hash:restablecida-por-admin');

            return $work();
        }
    };

    $handler = new ChangeOwnPasswordHandler(
        $users,
        $doubles->accounts,
        $doubles->hasher,
        $doubles->attempts,
        $doubles->tokens,
        $doubles->authJournal,
        $doubles->events,
        FixedClock::at('2026-10-07 09:00:00'),
        $racing,
        $doubles->telemetry(),
        new NullLogger,
    );

    expect($handler->handle(cambio()))->toBe(ChangeOwnPasswordOutcome::ChangedMeanwhile)
        ->and($doubles->accounts->passwords)->toBe([])
        ->and($doubles->events->published)->toBe([]);
})->group('RF-ID-10');

it('se deshace entero si el token de la sesion ya no existe', function (): void {
    [$doubles, $handler] = cambioPropioCon();
    $doubles->tokens->tokenStillExists = false;

    expect($handler->handle(cambio()))->toBe(ChangeOwnPasswordOutcome::SessionGone)
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->group('RF-ID-10');
