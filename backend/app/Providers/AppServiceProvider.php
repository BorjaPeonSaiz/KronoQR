<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Environment\ProductionSafetyGuard;
use App\Support\Network\BoundedReachability;
use App\Support\Queue\AfterCommitFailoverConnector;
use App\Support\Redis\CircuitBreakingPhpRedisConnector;
use App\Support\Redis\RedisCircuitBreaker;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Configuracion transversal de la aplicacion que no pertenece a ningun modulo.
 *
 * Lo que sea de un modulo va en su {Modulo}ServiceProvider, no aqui: este
 * fichero es el sitio donde se acumula lo que nadie quiso ubicar, y por eso se
 * mantiene lo mas vacio posible.
 *
 * Viven aqui la guarda de arranque de produccion, que no es de ningun modulo
 * porque no es del producto sino del DESPLIEGUE, y la degradacion de la cache y
 * de la cola cuando Redis no responde (CH1), que es de su configuracion y no de
 * quien las usa, con el cortacircuitos que evita reintentar Redis en cada acceso
 * mientras esta caido (R3-CH-01).
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El driver de la conexion `resilient` de config/queue.php. Se registra
        // al resolver el gestor de colas, que el framework carga en diferido.
        $this->app->afterResolving(QueueManager::class, static function (QueueManager $queues, $app): void {
            $queues->addConnector(
                'failover-after-commit',
                static fn (): AfterCommitFailoverConnector => new AfterCommitFailoverConnector(
                    $queues,
                    $app->make(Dispatcher::class),
                ),
            );
        });

        $this->registerRedisCircuitBreaker();
    }

    /**
     * The Redis circuit breaker (R3-CH-01): once Redis fails, no process tries
     * again for a few seconds and everything falls back at once. See
     * {@see RedisCircuitBreaker} for the why and the trade-offs.
     *
     * A singleton so that one request —or one long-lived worker— shares a
     * single view of the circuit, and an `afterResolving` because the Redis
     * manager is a deferred provider: nothing is built unless Redis is used.
     */
    private function registerRedisCircuitBreaker(): void
    {
        $this->app->singleton(RedisCircuitBreaker::class, static fn (Application $app): RedisCircuitBreaker => new RedisCircuitBreaker(
            stateFile: config()->string('database.redis_circuit_breaker.state_file'),
            openSeconds: config()->float('database.redis_circuit_breaker.seconds', 10.0),
            clock: new NativeClock,
            logger: $app->make(LoggerInterface::class),
        ));

        $this->app->afterResolving('redis', static function (mixed $redis, Application $app): void {
            if (! $redis instanceof RedisManager) {
                return; // A test double bound in its place.
            }

            // NOT static: `extend()` rebinds the closure to the manager, and a
            // static closure cannot be bound — the framework would silently
            // fall back to its own connector.
            $redis->extend('phpredis', fn (): CircuitBreakingPhpRedisConnector => new CircuitBreakingPhpRedisConnector(
                $app->make(RedisCircuitBreaker::class),
                new BoundedReachability,
            ));
        });
    }

    public function boot(): void
    {
        // Lo primero, y antes de que exista ninguna peticion. Ver el porque en
        // el docblock de ProductionSafetyGuard: con las trazas encendidas en
        // produccion, un error cualquiera publica las claves de la instalacion.
        ProductionSafetyGuard::assert(
            (string) $this->app->environment(),
            (bool) config('app.debug'),
        );

        $this->reportFailovers();
    }

    /**
     * La cache o la cola han dejado de usar Redis y siguen por su respaldo (CH1).
     *
     * El framework emite cada evento **una vez por transicion** —la primera
     * operacion que falla, no cada una—, asi que estas lineas no inundan el log
     * mientras dura la averia. Llevan el almacen o la conexion y la clase de la
     * excepcion, nunca su mensaje, que trae host y puerto: la misma disciplina
     * que la sonda de `/ready` (regla dura 21). Sin metrica, porque las metricas
     * viven en el mismo Redis que acaba de caer; quien alerta es la sonda.
     */
    private function reportFailovers(): void
    {
        Event::listen(static function (CacheFailedOver $event): void {
            Log::warning('cache.failed_over', [
                'store' => $event->storeName,
                'failure' => $event->exception::class,
            ]);
        });

        Event::listen(static function (QueueFailedOver $event): void {
            Log::warning('queue.failed_over', [
                'connection' => $event->connectionName,
                'job' => \is_object($event->command) ? $event->command::class : 'string',
                'failure' => $event->exception::class,
            ]);
        });
    }
}
