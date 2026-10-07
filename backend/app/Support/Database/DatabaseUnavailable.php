<?php

declare(strict_types=1);

namespace App\Support\Database;

use PDOException;
use Throwable;

/**
 * PostgreSQL cannot be reached, or was found unreachable less than the circuit
 * TTL ago (R3-CH-02).
 *
 * A `PDOException` on purpose: it travels through the framework exactly like
 * the driver's own connection error —`ConnectionFactory` skips to the next
 * host, `Connection::run()` wraps it in a `QueryException`— and the exception
 * handler finds it in the `previous` chain to answer `503` with `Retry-After`
 * instead of `500`, without reporting it: the outage is logged once, as
 * `database.circuit_opened`, not once per request.
 *
 * ## No host, no user, no chained cause
 *
 * The driver's message carries host, port and database user (hard rule 21). It
 * is not chained as `previous` either: a log formatter that walks the chain
 * would print it. The cause's CLASS is what the breaker logs.
 *
 * ## Why the message does not mention a lost connection
 *
 * Laravel retries a connection whose error "looks lost" (`causedByLostConnection`)
 * once in the connector and once more in the connection: that is how one PIN
 * clocking became eight DNS lookups. This message matches none of those
 * patterns, so the first failure is the last one.
 */
final class DatabaseUnavailable extends PDOException
{
    /**
     * Fragments of the libpq connection errors that mean "the server is not
     * there", as opposed to "it rejected you" (wrong password, unknown
     * database) or "it is busy" (`too many clients`). Only the first kind opens
     * the circuit: a full server must not become a ten-second outage, and a
     * wrong password is a configuration error that must keep saying so.
     *
     * @var list<string>
     */
    private const array UNREACHABLE = [
        'could not translate host name',
        'Name does not resolve',
        'Name or service not known',
        'Temporary failure in name resolution',
        'Connection refused',
        'timeout expired',
        'Connection timed out',
        'No route to host',
        'Network is unreachable',
        'could not connect to server',
        'the database system is starting up',
        'the database system is shutting down',
    ];

    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public static function circuitOpen(int $retryAfterSeconds): self
    {
        return new self('The database is marked as unavailable; not retrying the connection yet.', $retryAfterSeconds);
    }

    public static function unreachable(int $retryAfterSeconds): self
    {
        return new self('The database server is unreachable.', $retryAfterSeconds);
    }

    /**
     * Whether a connection error means the server is not reachable at all.
     */
    public static function isUnreachable(PDOException $failure): bool
    {
        return array_any(
            self::UNREACHABLE,
            static fn (string $fragment): bool => str_contains($failure->getMessage(), $fragment),
        );
    }

    /**
     * This exception, if it is `$failure` or anywhere in its `previous` chain.
     */
    public static function foundIn(Throwable $failure): ?self
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return $current;
            }
        }

        return null;
    }
}
