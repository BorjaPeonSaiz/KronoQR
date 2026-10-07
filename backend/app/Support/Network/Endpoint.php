<?php

declare(strict_types=1);

namespace App\Support\Network;

/**
 * Host and TCP port of a dependency, read from a Laravel connection array
 * (`database.connections.*`, `database.redis.*`) the way its driver reads it:
 * `url` first, then `host` and `port`.
 *
 * `null` when the configuration does not describe a TCP endpoint this code can
 * check on its own —a Unix socket, a read/write split, no host at all—: the
 * caller then lets the real driver decide, as it did before.
 */
final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    /**
     * @param  array<mixed>  $connection
     */
    public static function fromConfig(array $connection, int $defaultPort): ?self
    {
        [$host, $port] = self::hostAndPort($connection, $defaultPort);

        if (! \is_string($host) || $host === '' || str_starts_with($host, '/') || ! is_numeric($port)) {
            return null;
        }

        return new self(self::withoutScheme($host), (int) $port);
    }

    /**
     * @param  array<mixed>  $connection
     * @return array{0: mixed, 1: mixed}
     */
    private static function hostAndPort(array $connection, int $defaultPort): array
    {
        $url = $connection['url'] ?? null;

        if (! \is_string($url) || $url === '') {
            return [$connection['host'] ?? null, $connection['port'] ?? $defaultPort];
        }

        $parts = parse_url($url);

        return \is_array($parts) ? [$parts['host'] ?? null, $parts['port'] ?? $defaultPort] : [null, null];
    }

    /** `tls://redis` is `redis` for a TCP check. */
    private static function withoutScheme(string $host): string
    {
        $separator = strpos($host, '://');

        return $separator === false ? $host : substr($host, $separator + 3);
    }
}
