<?php

declare(strict_types=1);

namespace App\Support\Redis;

use RedisException;

/**
 * Thrown instead of connecting while the Redis circuit is open (R3-CH-01).
 *
 * It extends `RedisException` on purpose: everything that already degrades when
 * Redis does not answer —the `resilient` cache, the `failover-after-commit`
 * queue, `ThrottleScanFailOpen`, the metric writer and reader— recognises that
 * class and keeps doing exactly what it did, only without waiting for a network
 * timeout first.
 *
 * The message names no host, port or credential (hard rule 21): whoever logs
 * this exception only learns that the circuit was open.
 */
final class RedisCircuitOpen extends RedisException
{
    public static function make(): self
    {
        return new self('Redis is marked as unavailable; not retrying the connection yet.');
    }
}
