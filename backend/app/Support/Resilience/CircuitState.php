<?php

declare(strict_types=1);

namespace App\Support\Resilience;

/**
 * Where a dependency's circuit stands for the next connection attempt.
 */
enum CircuitState
{
    /** The dependency answered last time, or never failed: connect normally. */
    case Closed;

    /** It failed less than the configured TTL ago: do not even try. */
    case Open;

    /**
     * The TTL elapsed after a failure: one cheap, bounded reachability check
     * before paying for a full connection attempt.
     */
    case HalfOpen;
}
