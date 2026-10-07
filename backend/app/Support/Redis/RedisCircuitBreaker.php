<?php

declare(strict_types=1);

namespace App\Support\Redis;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Remembers, for a short while and across requests, that Redis is down
 * (R3-CH-01, RNF-D-03, hard rules 15 and 19).
 *
 * ## Why it exists
 *
 * With Redis gone, every cache read, limiter hit, session access, queue push and
 * metric write of a request opened a NEW connection attempt: the Redis manager
 * only keeps a connection once it has succeeded. On Docker Desktop each attempt
 * costs ~3.9 s of DNS for a container name that no longer exists, so one PIN
 * clocking took 12-45 s and 24 simultaneous ones exhausted the PHP-FPM pool.
 * Everything still degraded correctly —the clocking was registered— but the
 * server stopped answering anything else while it waited.
 *
 * ## What it does
 *
 * - The first failed connection attempt opens the circuit for `$openSeconds`.
 * - While open, every attempt fails at once with {@see RedisCircuitOpen}, a
 *   `RedisException`, so the existing fallbacks keep working and nothing new
 *   needs to know about this class.
 * - When the TTL elapses the circuit is half-open: the connector tries a cheap,
 *   bounded reachability check first and, only if it passes, a real connection.
 *   A success closes the circuit; a failure opens it for another TTL.
 *
 * ## Where the state lives, and why there
 *
 * In memory for the current process, and in a tiny file (`$stateFile`) holding
 * the instant until which the circuit stays open, so that the next PHP-FPM
 * request —a different process— does not pay for the timeout again. Not in
 * Redis, obviously, and not in the database: the breaker must keep working
 * when either is down. Reading it costs one `stat` per connection attempt, and
 * a healthy Redis is connected once per request.
 *
 * Every filesystem error is swallowed: a read-only or full disk degrades the
 * breaker to "per process only", never breaks a request.
 *
 * ## What it logs
 *
 * `redis.circuit_opened` once per outage (closed → open, not every TTL) and
 * `redis.circuit_closed` when Redis answers again. Only the exception class
 * and the TTL: a Redis error message carries host and port (hard rule 21).
 *
 * `$openSeconds <= 0` switches the breaker off entirely: every attempt goes to
 * Redis, as before. That is what the test suite uses by default, so a test that
 * takes Redis down does not decide for the next one.
 */
final class RedisCircuitBreaker
{
    /** Instant (Unix seconds) until which this process considers Redis down. */
    private float $openUntil = 0.0;

    public function __construct(
        private readonly string $stateFile,
        private readonly float $openSeconds,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return $this->openSeconds > 0.0;
    }

    public function state(): CircuitState
    {
        if (! $this->isEnabled()) {
            return CircuitState::Closed;
        }

        $now = $this->now();

        if ($this->openUntil > $now) {
            return CircuitState::Open;
        }

        $persisted = $this->readOpenUntil();

        if ($persisted === null) {
            $this->openUntil = 0.0;

            return CircuitState::Closed;
        }

        $this->openUntil = $persisted;

        return $persisted > $now ? CircuitState::Open : CircuitState::HalfOpen;
    }

    public function recordFailure(Throwable $failure): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $wasClosed = $this->openUntil <= 0.0 && $this->readOpenUntil() === null;

        $this->openUntil = $this->now() + $this->openSeconds;
        $this->writeOpenUntil($this->openUntil);

        if ($wasClosed) {
            $this->logger->warning('redis.circuit_opened', [
                'failure' => $failure::class,
                'open_seconds' => $this->openSeconds,
            ]);
        }
    }

    public function recordSuccess(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $wasOpen = $this->openUntil > 0.0 || $this->readOpenUntil() !== null;

        if (! $wasOpen) {
            return;
        }

        $this->openUntil = 0.0;
        $this->forget();

        $this->logger->info('redis.circuit_closed');
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function readOpenUntil(): ?float
    {
        try {
            if (! is_file($this->stateFile)) {
                return null;
            }

            $contents = file_get_contents($this->stateFile);
        } catch (Throwable) {
            return null;
        }

        if (! \is_string($contents) || ! is_numeric(trim($contents))) {
            return null;
        }

        return (float) trim($contents);
    }

    /**
     * Write to a sibling temporary file and rename it over the state file, so a
     * concurrent reader never sees half a number.
     */
    private function writeOpenUntil(float $until): void
    {
        $temporary = $this->stateFile.'.'.getmypid().'.tmp';
        $directory = \dirname($this->stateFile);

        // Checked first, not only caught: outside the framework's error handler
        // a failed write is a warning on the output, not an exception.
        if (! is_dir($directory) || ! is_writable($directory)) {
            return;
        }

        try {
            if (file_put_contents($temporary, \sprintf('%.6F', $until)) !== false) {
                rename($temporary, $this->stateFile);
            }
        } catch (Throwable) {
            // Per-process memory still holds: see the class docblock.
        }
    }

    private function forget(): void
    {
        try {
            if (is_file($this->stateFile)) {
                unlink($this->stateFile);
            }
        } catch (Throwable) {
            // A stale file only means one extra bounded check after its TTL.
        }
    }
}
