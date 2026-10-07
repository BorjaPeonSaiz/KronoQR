<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Resilience\CircuitBreaker;

/**
 * The PostgreSQL circuit (R3-CH-02): see {@see CircuitBreaker} for the
 * mechanism.
 *
 * ONE circuit for every `pgsql` connection of the installation —`pgsql`,
 * `error_events`, the migrator and the maintenance role—, because they all
 * point at the same server: one installation, one PostgreSQL (ADR-017). When it
 * is unreachable, it is unreachable for all of them.
 *
 * While open, {@see CircuitBreakingPostgresConnector} fails every connection at
 * once with {@see DatabaseUnavailable}, which the exception handler turns into
 * a `503` with `Retry-After` and does not report. Logs
 * `database.circuit_opened` and `database.circuit_closed`.
 */
final class DatabaseCircuitBreaker extends CircuitBreaker
{
    protected function component(): string
    {
        return 'database';
    }
}
