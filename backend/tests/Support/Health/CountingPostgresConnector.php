<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use App\Support\Database\CircuitBreakingPostgresConnector;
use PDO;

/**
 * The product's circuit-breaking PostgreSQL connector, counting the REAL
 * connection attempts that get past the breaker (R3-CH-02).
 *
 * Only {@see CircuitBreakingPostgresConnector::attempt()} is counted: an
 * attempt refused by the open circuit, or stopped by the half-open
 * reachability check, never reaches the network and is not an attempt.
 *
 * Static because the connection factory builds a new connector for every
 * connection it opens; reset it in `beforeEach`.
 */
final class CountingPostgresConnector extends CircuitBreakingPostgresConnector
{
    public static int $attempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
    }

    /**
     * @param  array<int, mixed>  $options
     */
    protected function attempt(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): PDO
    {
        self::$attempts++;

        return parent::attempt($dsn, $username, $password, $options);
    }
}
