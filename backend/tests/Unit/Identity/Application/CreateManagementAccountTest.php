<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\CreateManagementAccountCommand;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\Exception\ManagementAccountEmailTaken;
use App\Modules\Identity\Application\Port\ManagementAccountRegistry;
use App\Modules\Identity\Application\UseCase\CreateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\ManagementAccountProvisioned;
use App\Modules\Identity\Domain\Event\ManagementAccountCreated;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Identity\FixedTemporaryPasswordGenerator;
use Tests\Support\Identity\FixedTwoFactorAuthenticator;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\ManagementAccountDoubles;
use Tests\Support\Time\FixedClock;

/*
 * El alta de una cuenta de gestion con contrasena temporal (RF-ID-10, RF-ID-02),
 * sin framework ni base de datos.
 */

const CREATE_ACCOUNT_TEST_ACTOR = '0199c4a1-6f2d-7b10-9e3a-000000000042';

/** Registro en memoria: anota el alta y deja simular un correo ya usado. */
final class CreateAccountTestRegistry implements ManagementAccountRegistry
{
    /** @var list<array{email: string, hash: string, role: UserRole, expires: ?DateTimeImmutable}> */
    public array $created = [];

    public bool $taken = false;

    public function anyManagementAccountExists(): bool
    {
        return $this->created !== [];
    }

    public function emailTaken(string $email): bool
    {
        return $this->taken;
    }

    public function create(string $name, string $email, string $passwordHash, string $locale, UserRole $role, ?DateTimeImmutable $temporaryExpiresAt): AuthenticatedUser
    {
        $this->created[] = ['email' => $email, 'hash' => $passwordHash, 'role' => $role, 'expires' => $temporaryExpiresAt];

        return new AuthenticatedUser(
            uuid: InMemoryManagementAccounts::UUID,
            name: $name,
            email: $email,
            locale: $locale,
            roles: [$role],
            abilities: [],
            scope: AccessScope::unrestricted(),
            passwordStatus: PasswordStatus::Temporary,
        );
    }
}

/**
 * @return array{0: ManagementAccountDoubles, 1: CreateAccountTestRegistry, 2: CreateManagementAccountHandler}
 */
function altaDeCuentaCon(): array
{
    $doubles = new ManagementAccountDoubles(InMemoryManagementAccounts::empty()
        ->with(CREATE_ACCOUNT_TEST_ACTOR, 'admin@hotel.example', UserRole::ADMIN, twoFactor: true));
    $doubles->secrets->active[CREATE_ACCOUNT_TEST_ACTOR] = 'SECRETO';
    $registry = new CreateAccountTestRegistry;

    return [$doubles, $registry, new CreateManagementAccountHandler(
        $registry,
        $doubles->generator(),
        $doubles->hasher,
        $doubles->settings(),
        $doubles->reauthentication(),
        $doubles->events,
        FixedClock::at('2026-10-07 08:00:00'),
        $doubles->ledger,
        $doubles->telemetry(),
    )];
}

function altaDe(UserRole $role, string $code = FixedTwoFactorAuthenticator::VALID_CODE): CreateManagementAccountCommand
{
    return new CreateManagementAccountCommand('Direccion RRHH', 'rrhh@hotel.example', $role, 'es', CREATE_ACCOUNT_TEST_ACTOR, new ActorProof($code, null));
}

it('crea la cuenta con una temporal que caduca, y publica el alta y el rol', function (): void {
    [$doubles, $registry, $handler] = altaDeCuentaCon();

    $provisioned = $handler->handle(altaDe(UserRole::ADMIN));

    expect($provisioned->password->plain)->toBe(FixedTemporaryPasswordGenerator::PASSWORD)
        ->and($provisioned->issuedAt->format(DATE_ATOM))->toBe('2026-10-07T08:00:00+00:00')
        ->and($provisioned->expiresAt->format(DATE_ATOM))->toBe('2026-10-10T08:00:00+00:00')
        ->and($registry->created[0]['hash'])->toBe('hash:'.FixedTemporaryPasswordGenerator::PASSWORD)
        ->and($registry->created[0]['expires']?->format(DATE_ATOM))->toBe('2026-10-10T08:00:00+00:00')
        ->and(array_map(static fn (object $event): string => $event::class, $doubles->events->published))
        ->toBe([ManagementAccountCreated::class, ManagementRoleAssigned::class])
        ->and($doubles->metrics->changes)->toBe(['created:admin']);
})->group('RF-ID-10', 'RF-ID-02', 'RS-05');

it('no lleva al evento del alta ni el correo, ni el nombre, ni la contrasena', function (): void {
    [$doubles, , $handler] = altaDeCuentaCon();

    $handler->handle(altaDe(UserRole::RRHH));

    $created = $doubles->events->published[0];

    expect(array_keys(get_object_vars($created)))->toBe(['userUuid', 'actorUuid'])
        ->and((string) json_encode($created))->not->toContain('rrhh@hotel.example')
        ->and((string) json_encode($created))->not->toContain(FixedTemporaryPasswordGenerator::PASSWORD);
})->group('RF-ID-10', 'RS-05');

it('hashea fuera del candado y comprueba el correo dentro', function (): void {
    [$doubles, , $handler] = altaDeCuentaCon();

    $handler->handle(altaDe(UserRole::RRHH));

    expect($doubles->journal)->toBe(['hash', 'chain-lock:open', 'chain-lock:close']);
})->group('RF-ID-10');

it('rechaza un correo ya usado sin crear nada', function (): void {
    [$doubles, $registry, $handler] = altaDeCuentaCon();
    $registry->taken = true;

    expect(fn (): ManagementAccountProvisioned => $handler->handle(altaDe(UserRole::RRHH)))
        ->toThrow(ManagementAccountEmailTaken::class);

    expect($registry->created)->toBe([])
        ->and($doubles->events->published)->toBe([])
        ->and($doubles->metrics->changes)->toBe([]);
})->group('RF-ID-10');

it('exige la reautenticacion del admin antes de generar nada', function (): void {
    [$doubles, $registry, $handler] = altaDeCuentaCon();

    expect(fn (): ManagementAccountProvisioned => $handler->handle(altaDe(UserRole::RRHH, '111111')))
        ->toThrow(ActorReauthenticationFailed::class);

    expect($doubles->journal)->toBe([])
        ->and($registry->created)->toBe([]);
})->group('RF-ID-10', 'RS-06');

it('pide la contrasena del admin si no tiene segundo factor, y el error va en ese campo', function (): void {
    [$doubles, $registry, $handler] = altaDeCuentaCon();
    unset($doubles->secrets->active[CREATE_ACCOUNT_TEST_ACTOR]);

    $command = new CreateManagementAccountCommand('A', 'a@hotel.example', UserRole::AUDITOR, 'es', CREATE_ACCOUNT_TEST_ACTOR, new ActorProof(null, 'mala'));

    try {
        $handler->handle($command);
        $field = null;
    } catch (ActorReauthenticationFailed $failure) {
        $field = $failure->field;
    }

    expect($field)->toBe('actor_current_password')
        ->and($registry->created)->toBe([]);

    $ok = new CreateManagementAccountCommand('A', 'a@hotel.example', UserRole::AUDITOR, 'es', CREATE_ACCOUNT_TEST_ACTOR, new ActorProof(null, 'Contrasena-Actual-1!'));

    expect($handler->handle($ok)->account->roles)->toBe([UserRole::AUDITOR]);
})->group('RF-ID-10', 'RS-06');

it('responde en el campo de la contrasena si un admin con segundo factor la envia en lugar del codigo', function (): void {
    [, , $handler] = altaDeCuentaCon();

    $command = new CreateManagementAccountCommand('A', 'a@hotel.example', UserRole::AUDITOR, 'es', CREATE_ACCOUNT_TEST_ACTOR, new ActorProof(null, 'Contrasena-Actual-1!'));

    try {
        $handler->handle($command);
        $field = null;
    } catch (ActorReauthenticationFailed $failure) {
        $field = $failure->field;
    }

    expect($field)->toBe('actor_current_password');
})->group('RF-ID-10', 'RS-06');
