<?php

declare(strict_types=1);

namespace App\Support\Redis;

use App\Support\Resilience\CircuitBreaker;

/**
 * The Redis circuit (R3-CH-01): see {@see CircuitBreaker} for the mechanism.
 *
 * While open, {@see CircuitBreakingPhpRedisConnector} fails every connection at
 * once with {@see RedisCircuitOpen}, a `RedisException`, so the existing
 * fallbacks —the `resilient` cache, the `failover-after-commit` queue,
 * `ThrottleScanFailOpen`, the metric writer and reader— keep working without
 * waiting first. Logs `redis.circuit_opened` and `redis.circuit_closed`.
 */
final class RedisCircuitBreaker extends CircuitBreaker
{
    protected function component(): string
    {
        return 'redis';
    }
}
