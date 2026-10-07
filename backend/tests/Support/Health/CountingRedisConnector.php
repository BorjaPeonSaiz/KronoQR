<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use App\Support\Redis\CircuitBreakingPhpRedisConnector;
use Redis;

/**
 * The product's circuit-breaking connector, counting the REAL connection
 * attempts that get past the breaker (R3-CH-01).
 *
 * Only {@see CircuitBreakingPhpRedisConnector::attempt()} is counted: an
 * attempt refused by the open circuit, or stopped by the half-open
 * reachability check, never reaches the network and is not an attempt.
 *
 * Static because the Redis manager builds a new connector for every connection
 * it resolves; reset it in `beforeEach`.
 */
final class CountingRedisConnector extends CircuitBreakingPhpRedisConnector
{
    public static int $attempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function attempt(array $config): Redis
    {
        self::$attempts++;

        return parent::attempt($config);
    }
}
