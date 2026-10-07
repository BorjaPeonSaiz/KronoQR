<?php

declare(strict_types=1);

namespace App\Support\Redis;

use RedisException;
use Throwable;

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

    /**
     * Whether `$failure`, or anything in its `previous` chain, is this
     * exception: the exception handler does not report those (the outage is
     * already logged once, as `redis.circuit_opened`).
     */
    public static function isCauseOf(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return true;
            }
        }

        return false;
    }
}
