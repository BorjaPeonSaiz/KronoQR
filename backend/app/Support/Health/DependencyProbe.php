<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Network\BoundedReachability;
use App\Support\Network\Endpoint;
use App\Support\Redis\RedisCircuitBreaker;
use App\Support\Resilience\CircuitState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\ConnectionResolverInterface as Connections;
use Throwable;

/**
 * Las dependencias que necesita una peticion real, comprobadas de verdad.
 *
 * Es lo que responde `GET /api/v1/ready`, la sonda que gobierna el despliegue
 * sin parada (RNF-D-04) y la comprobacion posterior a una actualizacion
 * (RF-PD-10). Si esto dice que no, el orquestador no manda trafico a esta
 * instancia.
 *
 * ## Que se comprueba y por que solo eso
 *
 * PostgreSQL y Redis, que son las dos piezas sin las cuales una peticion del
 * producto no puede terminar: el registro horario vive en la primera y los
 * limites de peticiones, los contadores de intentos y las metricas en la
 * segunda. Se comprueban con la operacion mas barata que **abre la conexion de
 * verdad** —`select 1` y `PING`—, no leyendo configuracion: una sonda que solo
 * mira que el host este escrito en el `.env` da verde con la base de datos
 * apagada.
 *
 * No se comprueba el disco, ni el correo, ni los certificados. Eso es la
 * comprobacion de salud posinstalacion (RF-PD-13), que es una accion
 * autenticada del administrador y puede permitirse tardar; esta la ejecuta un
 * orquestador cada pocos segundos.
 *
 * ## Se para en el primer fallo
 *
 * El desenlace es el mismo con una dependencia caida que con las dos —`503`,
 * sin decir cual—, asi que seguir preguntando solo anade latencia a una
 * instalacion que ya esta en problemas.
 *
 * ## A time budget per dependency that includes DNS (R3-CH-02)
 *
 * Before the real query, each dependency's host must resolve and accept a TCP
 * connection within {@see self::BUDGET_SECONDS}, checked by
 * {@see BoundedReachability}. Driver timeouts do not cover name resolution:
 * with the PostgreSQL container gone, `/ready` took 15.7 s —four ~3.9 s DNS
 * lookups, one per driver attempt—, and an orchestrator with a 1-5 s probe saw
 * "the probe does not answer" instead of "not ready". Now a missing host or a
 * refused port is a `503` in under three seconds, logged as
 * {@see EndpointUnreachable}.
 *
 * Each dependency shares its circuit breaker with the requests
 * ({@see DatabaseCircuitBreaker}, {@see RedisCircuitBreaker}), both ways: a
 * failed reachability check here opens it —so the requests stop trying too—,
 * and while it is open the query or the PING fails at once without spending
 * the budget. `/ready` may therefore report a dependency as down for up to the
 * breaker TTL after it comes back. That is deliberate: the readiness probe sees
 * what the requests see.
 *
 * ## No es la sonda de vida
 *
 * `GET /api/v1/health` no pasa por aqui a proposito: una sonda de vida que toca
 * dependencias reinicia el contenedor de PHP cuando lo que esta caido es
 * PostgreSQL, que es exactamente el fallo que no se quiere.
 */
final readonly class DependencyProbe
{
    /**
     * Seconds each dependency gets to resolve its host and accept a TCP
     * connection before `/ready` gives up on it (R3-CH-02). With both checks
     * failing at the limit the probe still answers in under three seconds.
     */
    public const float BUDGET_SECONDS = 2.0;

    public function __construct(
        private Connections $connections,
        private Redis $redis,
        private Config $config,
        private BoundedReachability $reachability,
        private RedisCircuitBreaker $redisBreaker,
        private DatabaseCircuitBreaker $databaseBreaker,
    ) {}

    public function firstFailure(): ?DependencyFailure
    {
        // Same for both dependencies: with the circuit already open the query or
        // the PING fails at once, so checking reachability first would only add
        // up to the budget to every probe; when the check fails, the circuit
        // opens for the requests too.
        if ($this->databaseBreaker->state() !== CircuitState::Open && ! $this->databaseReachable()) {
            $this->databaseBreaker->recordFailure(new EndpointUnreachable);

            return new DependencyFailure('database', EndpointUnreachable::class);
        }

        try {
            $this->connections->connection()->select('select 1');
        } catch (Throwable $exception) {
            return new DependencyFailure('database', (DatabaseUnavailable::foundIn($exception) ?? $exception)::class);
        }

        if ($this->redisBreaker->state() !== CircuitState::Open && ! $this->redisReachable()) {
            $this->redisBreaker->recordFailure(new EndpointUnreachable);

            return new DependencyFailure('redis', EndpointUnreachable::class);
        }

        try {
            $this->redis->connection()->command('PING', []);
        } catch (Throwable $exception) {
            return new DependencyFailure('redis', $exception::class);
        }

        return null;
    }

    /**
     * The default database connection: `null` when it does not exist, and then
     * the real query decides.
     */
    private function databaseReachable(): bool
    {
        $name = $this->config->get('database.default');
        $connection = \is_string($name) ? $this->config->get('database.connections.'.$name) : null;

        return $this->reachability->reachableConnection($connection, Endpoint::POSTGRES_PORT, self::BUDGET_SECONDS);
    }

    /**
     * The default Redis connection, the one the PING below uses.
     */
    private function redisReachable(): bool
    {
        return $this->reachability->reachableConnection(
            $this->config->get('database.redis.default'),
            Endpoint::REDIS_PORT,
            self::BUDGET_SECONDS,
        );
    }
}
