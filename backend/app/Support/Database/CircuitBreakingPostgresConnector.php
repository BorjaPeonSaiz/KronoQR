<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Network\BoundedReachability;
use App\Support\Network\Endpoint;
use App\Support\Resilience\CircuitState;
use Illuminate\Database\Connectors\PostgresConnector;
use PDO;
use PDOException;

/**
 * The framework's PostgreSQL connector with a circuit breaker in front of every
 * connection attempt (R3-CH-02).
 *
 * Bound as `db.connector.pgsql`, the hook `ConnectionFactory` offers for this,
 * so it serves every `pgsql` connection: `pgsql`, `error_events`, the migrator
 * and the maintenance role.
 *
 * - Open circuit: {@see DatabaseUnavailable} at once, no network.
 * - Half-open: a {@see BoundedReachability} check bounded by the connection
 *   timeout (`DB_CONNECT_TIMEOUT`, which is `PDO::ATTR_TIMEOUT`) before the real
 *   attempt, so probing a server that is still gone costs that budget and not
 *   a ~4 s DNS lookup.
 * - Closed: the framework's behaviour, except that a connection error meaning
 *   "the server is not there" ({@see DatabaseUnavailable::isUnreachable()})
 *   opens the circuit and becomes a {@see DatabaseUnavailable}, which the
 *   framework does not retry. Any other error —wrong password, too many
 *   clients— is rethrown untouched.
 *
 * ## No pre-check while closed
 *
 * Checking DNS and TCP before the first connection of every request would bound
 * even the first failure of an outage —~2 s instead of the ~3.9 s DNS lookup
 * of Docker Desktop—, but it would spawn a child process on every request that
 * touches the database, for the whole life of the installation (measured on the
 * development stack: 1.6 ms median, 6.7 ms worst of 20, against 7.7 ms for the
 * PDO connection itself). The gain only reaches the requests already in flight
 * when an outage starts: once the circuit is open, re-probing is already
 * bounded by the half-open check. Not worth a fork per request.
 *
 * With the breaker switched off (`DB_CIRCUIT_BREAKER_SECONDS=0`) nothing here
 * changes the framework's behaviour.
 */
class CircuitBreakingPostgresConnector extends PostgresConnector
{
    /** Budget of the half-open check when the connection sets no timeout. */
    private const float DEFAULT_PROBE_SECONDS = 2.0;

    public function __construct(
        private readonly DatabaseCircuitBreaker $breaker,
        private readonly BoundedReachability $reachability,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        if ($this->breaker->state() === CircuitState::HalfOpen && ! $this->looksReachable($config)) {
            $failure = DatabaseUnavailable::unreachable($this->breaker->retryAfterSeconds());
            $this->breaker->recordFailure($failure);

            throw $failure;
        }

        return parent::connect($config);
    }

    /**
     * Every PDO the framework creates passes here, retries included.
     *
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array<int, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): PDO
    {
        if ($this->breaker->state() === CircuitState::Open) {
            throw DatabaseUnavailable::circuitOpen($this->breaker->retryAfterSeconds());
        }

        try {
            $pdo = $this->attempt($dsn, $username, $password, $options);
        } catch (PDOException $failure) {
            if (! $this->breaker->isEnabled() || ! DatabaseUnavailable::isUnreachable($failure)) {
                throw $failure;
            }

            $this->breaker->recordFailure($failure);

            throw DatabaseUnavailable::unreachable($this->breaker->retryAfterSeconds());
        }

        $this->breaker->recordSuccess();

        return $pdo;
    }

    /**
     * The real connection attempt. A seam for the tests, which count attempts.
     *
     * @param  array<int, mixed>  $options
     */
    protected function attempt(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): PDO
    {
        // @phpstan-ignore argument.type, argument.type (Laravel itself calls this with `$config['username'] ?? null` and `$config['password'] ?? null`: its docblock is narrower than its own caller, and PDO accepts null for both)
        return parent::createPdoConnection($dsn, $username, $password, $options);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function looksReachable(array $config): bool
    {
        $options = $config['options'] ?? [];

        return $this->reachability->reachableConnection(
            $config,
            Endpoint::POSTGRES_PORT,
            BoundedReachability::budget(\is_array($options) ? ($options[PDO::ATTR_TIMEOUT] ?? null) : null, self::DEFAULT_PROBE_SECONDS),
        );
    }
}
