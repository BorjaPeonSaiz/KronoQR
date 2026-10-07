<?php

declare(strict_types=1);

namespace App\Support\Network;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * "Can I open a TCP connection to this host and port?", answered within a hard
 * time budget that INCLUDES the name resolution (R3-CH-01, R3-CH-02).
 *
 * ## Why the DNS needs its own limit
 *
 * Neither `PDO::ATTR_TIMEOUT` (libpq's `connect_timeout`) nor phpredis'
 * connection timeout covers resolving the host name: both call the blocking
 * `getaddrinfo()` first and only start the clock afterwards. When a Compose
 * service disappears, Docker's embedded resolver forwards its name upstream and
 * the answer takes ~3.9 s on Docker Desktop, once per attempt. `/ready` took
 * 15.7 s that way with PostgreSQL stopped. PHP offers no resolver call with a
 * timeout, so the lookup runs as `getent ahosts` —the same libc resolver and the
 * same `/etc/hosts`, `nsswitch` and `resolv.conf` rules the drivers use— in a
 * child process that is killed when the budget runs out.
 *
 * Literal IPs skip the lookup. If `getent` cannot be run at all, the check falls
 * back to letting the socket resolve the name: no worse than before, never a
 * false "unreachable".
 *
 * This is a pre-check, not a health check: it says nothing about credentials
 * or the protocol. Callers still talk to the real service afterwards.
 */
final readonly class BoundedReachability
{
    /** `getent`'s exit code for "no such key": the name does not resolve. */
    private const int GETENT_NOT_FOUND = 2;

    /**
     * The same check on a Laravel connection array (`url`, or `host` and
     * `port`), the one place the two connectors and `/ready` share.
     *
     * `true` when the array does not describe a TCP endpoint this check
     * understands —a Unix socket, a read/write split, no array at all—: then
     * the real driver decides, as it did before the check existed.
     */
    public function reachableConnection(mixed $connection, int $defaultPort, float $seconds): bool
    {
        $endpoint = \is_array($connection) ? Endpoint::fromConfig($connection, $defaultPort) : null;

        return ! $endpoint instanceof Endpoint || $this->reachable($endpoint->host, $endpoint->port, $seconds);
    }

    /**
     * A driver's connection timeout as a budget for this check: `$fallback`
     * when it is missing, zero or not a number.
     */
    public static function budget(mixed $timeout, float $fallback): float
    {
        return is_numeric($timeout) && (float) $timeout > 0.0 ? (float) $timeout : $fallback;
    }

    public function reachable(string $host, int $port, float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);

        $addresses = $this->resolve($host, $seconds);

        // Every address the name resolves to, in the resolver's order, within
        // what is left of the budget: `localhost` is `::1` first and a service
        // may listen on IPv4 only. A refused port costs nothing; only an address
        // that does not answer eats the budget.
        foreach ($addresses as $address) {
            $remaining = ($deadline - hrtime(true)) / 1e9;

            if ($remaining <= 0.0) {
                return false;
            }

            if ($this->connects($address, $port, $remaining)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The addresses to try: the IPs the name resolved to, the name itself if
     * resolution could not be attempted, none if it failed or ran out of time.
     *
     * @return list<string>
     */
    private function resolve(string $host, float $seconds): array
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }

        // A host name never starts with a dash, and `getent` would read one as
        // an option. Not `getent ahosts -- name`: Alpine's `getent` takes `--`
        // for the key itself and then resolves nothing.
        if ($bare === '' || str_starts_with($bare, '-')) {
            return [];
        }

        try {
            // `ahosts` and not `hosts`: every address, IPv4 and IPv6, as
            // `getaddrinfo()` would hand them to the driver.
            $lookup = new Process(['getent', 'ahosts', $bare]);
            $lookup->setTimeout($seconds);
            $lookup->run();
        } catch (Throwable $failure) {
            // Timed out: the resolver did not answer within the budget. Any
            // other failure means the lookup could not run at all.
            return $failure instanceof ProcessTimedOutException ? [] : [$bare];
        }

        if ($lookup->getExitCode() === self::GETENT_NOT_FOUND) {
            return [];
        }

        if (! $lookup->isSuccessful()) {
            return [$bare];
        }

        return $this->addressesIn($lookup->getOutput()) ?: [$bare];
    }

    /**
     * The distinct IPs of a `getent ahosts` listing (one line per address and
     * socket type), in order.
     *
     * @return list<string>
     */
    private function addressesIn(string $listing): array
    {
        $addresses = [];

        foreach (preg_split('/\R/', trim($listing)) ?: [] as $line) {
            $address = strtok($line, " \t");

            if (\is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[$address] = true;
            }
        }

        return array_map(strval(...), array_keys($addresses));
    }

    private function connects(string $address, int $port, float $seconds): bool
    {
        $target = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        try {
            $socket = stream_socket_client('tcp://'.$target.':'.$port, $errorCode, $errorMessage, $seconds);
        } catch (Throwable) {
            return false;
        }

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
