<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountMetrics;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Port\TemporaryPasswordGenerator;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Application\Support\ActorReauthentication;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Application\Support\TemporaryPasswordSettings;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\TemporaryPasswordLifetime;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * Los dobles de los casos de uso del ciclo de vida de las cuentas de gestion
 * (RF-ID-10), sin framework ni base de datos.
 *
 * **Un diario compartido** (`ManagementAccountDoubles::$journal`) donde cada
 * doble apunta lo que se le pide, en orden: es lo que deja afirmar que el hash
 * se calcula **fuera** del candado de la cadena y que la fila se bloquea
 * **dentro** (ADR-010).
 */
final class ManagementAccountDoubles
{
    /** @var list<string> */
    public array $journal = [];

    public readonly InMemoryManagementAccounts $accounts;

    public readonly RecordingAccessTokens $tokens;

    public readonly RecordingIdentityEvents $events;

    public readonly OrderedLedgerWrite $ledger;

    public readonly JournaledPasswordHasher $hasher;

    public readonly InMemoryLoginAttempts $attempts;

    public readonly InMemoryTwoFactorSecrets $secrets;

    public readonly RecordingManagementAccountMetrics $metrics;

    public readonly RecordingAuthenticationJournal $authJournal;

    public function __construct(?InMemoryManagementAccounts $accounts = null)
    {
        $this->accounts = $accounts ?? InMemoryManagementAccounts::empty();
        $this->tokens = new RecordingAccessTokens;
        $this->events = new RecordingIdentityEvents;
        $this->ledger = new OrderedLedgerWrite($this);
        $this->hasher = new JournaledPasswordHasher($this);
        $this->attempts = new InMemoryLoginAttempts(3);
        $this->secrets = new InMemoryTwoFactorSecrets;
        $this->metrics = new RecordingManagementAccountMetrics;
        $this->authJournal = new RecordingAuthenticationJournal;
    }

    public function note(string $entry): void
    {
        $this->journal[] = $entry;
    }

    public function telemetry(): ManagementAccountTelemetry
    {
        return new ManagementAccountTelemetry(new NullLogger, $this->metrics);
    }

    public function settings(): TemporaryPasswordSettings
    {
        return new TemporaryPasswordSettings(new TemporaryPasswordLifetime(72), 12);
    }

    public function generator(): TemporaryPasswordGenerator
    {
        return new FixedTemporaryPasswordGenerator;
    }

    public function reauthentication(): ActorReauthentication
    {
        return new ActorReauthentication(
            $this->secrets,
            new FixedTwoFactorAuthenticator,
            $this->accounts,
            $this->hasher,
            $this->attempts,
            $this->authJournal,
        );
    }

    /**
     * Una conexion que solo acepta el candado del padron, y lo apunta.
     */
    public function connection(): ConnectionInterface
    {
        /** @var ConnectionInterface&MockInterface $connection */
        $connection = Mockery::mock(ConnectionInterface::class);

        $connection->shouldReceive('statement')
            ->andReturnUsing(function (string $sql): bool {
                $this->note('roster-lock');

                return true;
            });

        return $connection;
    }
}

/**
 * Ejecuta el trabajo apuntando cuando se toma y se suelta el candado. Con
 * `$abort`, no lo ejecuta: lo que se observe despues es lo que el caso de uso
 * hizo FUERA de la transaccion.
 */
final class OrderedLedgerWrite implements SerializedLedgerWrite
{
    public bool $abort = false;

    public function __construct(private readonly ManagementAccountDoubles $doubles) {}

    public function withChainLock(callable $work): mixed
    {
        if ($this->abort) {
            throw new LogicException('Transaccion abortada por la prueba.');
        }

        $this->doubles->note('chain-lock:open');

        try {
            return $work();
        } finally {
            $this->doubles->note('chain-lock:close');
        }
    }
}

/** `hash:<valor>` y no `bcrypt`: lo que importa es CUANDO se hashea. */
final readonly class JournaledPasswordHasher implements PasswordHasher
{
    public function __construct(private ManagementAccountDoubles $doubles) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        $this->doubles->note('hash');

        return 'hash:'.$password;
    }

    public function matches(#[SensitiveParameter] string $password, string $hash): bool
    {
        $this->doubles->note('matches');

        return 'hash:'.$password === $hash;
    }
}

final class FixedTemporaryPasswordGenerator implements TemporaryPasswordGenerator
{
    public const string PASSWORD = 'Kd2pQ9vLmN4tZbYc#F7w';

    public function generate(int $minLength): string
    {
        return self::PASSWORD;
    }
}

/** Acepta `246802` en la franja 7 y nada mas; respeta la anti-reutilizacion. */
final class FixedTwoFactorAuthenticator implements TwoFactorAuthenticator
{
    public const string VALID_CODE = '246802';

    public function generateSecret(): string
    {
        return 'JBSWY3DPEHPK3PXP';
    }

    public function otpauthUriFor(string $account, #[SensitiveParameter] string $secret): string
    {
        return 'otpauth://totp/'.$account;
    }

    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, ?int $notBeforeSlice): ?int
    {
        if ($code !== self::VALID_CODE) {
            return null;
        }

        return $notBeforeSlice !== null && $notBeforeSlice >= 7 ? null : 7;
    }
}

final class InMemoryTwoFactorSecrets implements TwoFactorSecrets
{
    /** @var array<string, string> */
    public array $active = [];

    /** @var array<string, int> */
    public array $slices = [];

    public function activeSecretFor(string $uuid): ?string
    {
        return $this->active[$uuid] ?? null;
    }

    public function unconfirmedSecretFor(string $uuid): ?string
    {
        return null;
    }

    public function storeUnconfirmedSecret(string $uuid, #[SensitiveParameter] string $secret): void {}

    public function confirm(string $uuid, DateTimeImmutable $at): void {}

    public function forget(string $uuid): void
    {
        unset($this->active[$uuid], $this->slices[$uuid]);
    }

    public function lastAcceptedSliceFor(string $uuid): ?int
    {
        return $this->slices[$uuid] ?? null;
    }

    public function rememberAcceptedSlice(string $uuid, int $slice): void
    {
        $this->slices[$uuid] = $slice;
    }
}

/** Bloquea al alcanzar el umbral; `secondsUntilUnlock` fijo. */
final class InMemoryLoginAttempts implements LoginAttempts
{
    /** @var array<string, int> */
    public array $failures = [];

    /** @var list<string> */
    public array $cleared = [];

    public function __construct(private readonly int $threshold) {}

    public function isLocked(string $key): bool
    {
        return ($this->failures[$key] ?? 0) >= $this->threshold;
    }

    public function secondsUntilUnlock(string $key): int
    {
        return 900;
    }

    public function recordFailure(string $key): void
    {
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
    }

    public function clear(string $key): void
    {
        $this->cleared[] = $key;
        unset($this->failures[$key]);
    }
}

final class RecordingManagementAccountMetrics implements ManagementAccountMetrics
{
    /** @var list<string> */
    public array $changes = [];

    public function changed(ManagementAccountChange $action, UserRole $role): void
    {
        $this->changes[] = $action->value.':'.$role->value;
    }
}

final class RecordingAuthenticationJournal implements AuthenticationJournal
{
    /** @var list<string> */
    public array $lockouts = [];

    public function succeeded(AuthChannel $channel, string $subjectUuid): void {}

    public function failed(AuthChannel $channel, ?string $subjectUuid, AuthFailureReason $reason): void {}

    public function lockoutStarted(AuthChannel $channel, ?string $subjectUuid, int $lockSeconds): void
    {
        $this->lockouts[] = (string) $subjectUuid;
    }

    public function originLocked(AuthChannel $channel, string $origin, int $failures, int $lockSeconds, bool $audited): void {}

    public function originUnlocked(AuthChannel $channel, string $origin, bool $hadState): void {}

    public function loggedOut(AuthChannel $channel, ?string $subjectUuid): void {}
}

/** Una cuenta de gestion por `uuid`, con los ambitos que se le den. */
final class InMemoryUserAccounts implements UserAccounts
{
    /** @var array<string, AuthenticatedUser> */
    public array $users = [];

    public function verifyCredentials(string $email, string $password): ?AuthenticatedUser
    {
        return null;
    }

    public function findByUuid(string $uuid): ?AuthenticatedUser
    {
        return $this->users[$uuid] ?? null;
    }

    public function recordSuccessfulLogin(string $uuid, DateTimeImmutable $at): void {}
}
