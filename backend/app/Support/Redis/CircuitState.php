<?php

declare(strict_types=1);

namespace App\Support\Redis;

/**
 * Where the Redis circuit stands for the next connection attempt.
 */
enum CircuitState
{
    /** Redis answered last time, or never failed: connect normally. */
    case Closed;

    /** Redis failed less than the configured TTL ago: do not even try. */
    case Open;

    /**
     * The TTL elapsed after a failure: one cheap, bounded reachability check
     * before paying for a full connection attempt.
     */
    case HalfOpen;
}
