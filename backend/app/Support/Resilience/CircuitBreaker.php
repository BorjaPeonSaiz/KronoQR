<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Redis\RedisCircuitBreaker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Remembers, for a short while and across requests, that a dependency is down
 * (R3-CH-01, R3-CH-02, RNF-D-03, hard rules 15 and 19).
 *
 * ## Why it exists
 *
 * A request that touches a dead dependency several times —Redis for the cache,
 * the limiter, the queue and the metrics; PostgreSQL for the token, the
 * clocking and the error history— used to pay a full connection attempt each
 * time, because the framework only keeps a connection once it has succeeded.
 * With the container gone from Docker's DNS each attempt costs ~3.9 s: a PIN
 * clocking took 12-45 s with Redis down and 31 s with PostgreSQL down, and a
 * burst of them exhausted the PHP-FPM pool.
 *
 * ## What it does
 *
 * - The first failed connection attempt opens the circuit for `$openSeconds`.
 * - While open, the connector fails every attempt at once, without network.
 * - When the TTL elapses the circuit is half-open: the connector tries a cheap,
 *   bounded reachability check first and, only if it passes, a real connection.
 *   A success closes the circuit; a failure opens it for another TTL.
 *
 * One subclass per dependency ({@see RedisCircuitBreaker},
 * {@see DatabaseCircuitBreaker}): each is its own
 * container singleton with its own state file and its own log events.
 *
 * ## Where the state lives, and why there
 *
 * In memory for the current process, and in a tiny file (`$stateFile`) holding
 * the instant until which the circuit stays open, so that the next PHP-FPM
 * request —a different process— does not pay for the timeout again. Not in
 * Redis and not in the database: the breaker must keep working when either is
 * down. Reading it costs one `stat` per connection attempt, and a healthy
 * dependency is connected once per request.
 *
 * Every filesystem error is swallowed: a read-only or full disk degrades the
 * breaker to "per process only", never breaks a request.
 *
 * ## What it logs
 *
 * `<component>.circuit_opened` once per outage (closed → open, not every TTL)
 * and `<component>.circuit_closed` when the dependency answers again. Only the
 * exception class and the TTL: a driver's error message carries host, port and
 * sometimes the database user (hard rule 21).
 *
 * `$openSeconds <= 0` switches the breaker off entirely: every attempt goes to
 * the dependency, as before. That is what the test suite uses by default, so a
 * test that takes a dependency down does not decide for the next one.
 */
abstract class CircuitBreaker
{
    /** Instant (Unix seconds) until which this process considers the dependency down. */
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

    /**
     * How long an outage is remembered: what a client should wait before
     * retrying (`Retry-After`), rounded up and never below one second.
     */
    public function retryAfterSeconds(): int
    {
        return max(1, (int) ceil($this->openSeconds));
    }

    /**
     * The prefix of this breaker's log events: `redis`, `database`.
     */
    abstract protected function component(): string;

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
            $this->logger->warning($this->component().'.circuit_opened', [
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

        $this->logger->info($this->component().'.circuit_closed');
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
