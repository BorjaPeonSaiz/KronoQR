<?php

declare(strict_types=1);

namespace App\Support\Redis;

use App\Support\Network\BoundedReachability;
use App\Support\Network\Endpoint;
use App\Support\Resilience\CircuitState;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Redis;
use RedisException;

/**
 * The framework's phpredis connector with a circuit breaker in front of every
 * connection attempt (R3-CH-01).
 *
 * It overrides `createClient()` and not `connect()` because the framework
 * re-runs `createClient()` from inside `PhpRedisConnection` when a connected
 * client "went away": reconnections go through the breaker as well.
 *
 * - Open circuit: {@see RedisCircuitOpen} at once, no network.
 * - Half-open: a {@see BoundedReachability} check bounded by the connection
 *   timeout (`REDIS_TIMEOUT`) before the real attempt, so probing a Redis that
 *   is still gone costs that budget and not a full DNS round of ~4 s.
 * - Closed: exactly the framework's behaviour; a `RedisException` while
 *   connecting, authenticating or selecting the database opens the circuit and
 *   is rethrown untouched.
 *
 * Only the phpredis client, which is the product's (`REDIS_CLIENT`). Clusters
 * are not used by the product and keep the framework's connector.
 */
class CircuitBreakingPhpRedisConnector extends PhpRedisConnector
{
    /** Budget of the half-open check when the connection sets no timeout. */
    private const float DEFAULT_PROBE_SECONDS = 1.0;

    public function __construct(
        private readonly RedisCircuitBreaker $breaker,
        private readonly BoundedReachability $reachability,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws RedisException
     */
    protected function createClient(array $config): Redis
    {
        $state = $this->breaker->state();

        if ($state === CircuitState::Open) {
            throw RedisCircuitOpen::make();
        }

        if ($state === CircuitState::HalfOpen && ! $this->looksReachable($config)) {
            $failure = RedisCircuitOpen::make();
            $this->breaker->recordFailure($failure);

            throw $failure;
        }

        try {
            $client = $this->attempt($config);
        } catch (RedisException $failure) {
            $this->breaker->recordFailure($failure);

            throw $failure;
        }

        $this->breaker->recordSuccess();

        return $client;
    }

    /**
     * The real connection attempt. A seam for the tests, which count attempts.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RedisException
     */
    protected function attempt(array $config): Redis
    {
        return parent::createClient($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function looksReachable(array $config): bool
    {
        // The framework has already resolved `url` into `host` and `port` here.
        return $this->reachability->reachableConnection(
            $config,
            Endpoint::REDIS_PORT,
            BoundedReachability::budget($config['timeout'] ?? null, self::DEFAULT_PROBE_SECONDS),
        );
    }
}
