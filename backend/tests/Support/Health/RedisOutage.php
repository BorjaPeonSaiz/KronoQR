<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use App\Support\Observability\Metrics\RedisMetricWriter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;

/**
 * **Redis de verdad, inalcanzable de verdad** (CH1).
 *
 * Al contrario que {@see UnavailableRedis}, que sustituye el cliente por un
 * doble, esto deja el cliente `phpredis` del producto intacto y lo apunta a un
 * puerto donde no escucha nadie: la `RedisException` que salta es la misma
 * —«Connection refused»— que en una instalacion con el contenedor de Redis
 * parado, y la lanza el mismo codigo. Es lo que la verificacion de la 2.1.0 hizo
 * a mano, y lo que el doble no puede reproducir: el limitador, la cache y la cola
 * llegan a Redis por caminos distintos, y solo uno de ellos pasa por la fabrica
 * que el doble sustituye.
 *
 * Ademas pone la cache, el limitador y la cola sobre Redis, como en produccion:
 * `phpunit.xml` los deja en `array` y `sync`, y con eso la caida no tocaria nada.
 *
 * **Deja escrito el disco.** La cache por defecto cae al almacen `file` durante
 * la caida; {@see self::end()} lo vacia para que la prueba siguiente no herede
 * contadores.
 *
 * ## Por que hay que rehacer piezas del contenedor
 *
 * `RedisManager` copia su configuracion al construirse, y la cache, el limitador
 * y los adaptadores que ya se resolvieron guardan una referencia al gestor
 * antiguo. Cambiar la configuracion sin olvidarlos dejaria la prueba hablando con
 * el Redis sano. Por eso {@see self::begin()} comprueba al final que el cliente
 * de verdad falla: una prueba de caida que no cae daria un verde falso.
 */
final class RedisOutage
{
    /** Un puerto reservado donde no escucha nada: conexion rechazada al instante. */
    private const int DEAD_PORT = 1;

    public static function begin(): void
    {
        foreach (['default', 'cache'] as $connection) {
            config()->set("database.redis.{$connection}.host", '127.0.0.1');
            config()->set("database.redis.{$connection}.port", self::DEAD_PORT);
        }

        // Lo que `config/cache.php` resuelve con `CACHE_STORE=redis`, que es lo
        // que lleva toda instalacion real.
        config()->set('cache.default', 'resilient');
        config()->set('cache.stores.resilient.stores', ['redis', 'file']);
        config()->set('cache.limiter', 'redis');
        // El disco de la caida, fuera de `storage/`: el arbol esta montado desde
        // el anfitrion y lo comparte la aplicacion de desarrollo.
        config()->set('cache.stores.file.path', sys_get_temp_dir().'/kronoqr-redis-outage-cache');
        config()->set('cache.stores.file.lock_path', sys_get_temp_dir().'/kronoqr-redis-outage-cache');
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.resilient.connections', ['redis', 'deferred']);

        $limiters = self::registeredLimiters();

        foreach ([
            'redis', 'redis.connection',
            'cache', 'cache.store', 'cache.psr6',
            'queue', 'queue.connection',
            RateLimiter::class,
            PinAttempts::class,
            RedisMetricWriter::class,
            RedisMetricReader::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }

        Facade::clearResolvedInstances();

        $rateLimiter = app(RateLimiter::class);

        foreach ($limiters as $name => $limiter) {
            $rateLimiter->for($name, $limiter);
        }

        self::assertRedisIsDown();

        app('cache')->store('file')->flush();
    }

    /**
     * Vacia lo que la caida dejo en el almacen `file`, que sobrevive al proceso.
     */
    public static function end(): void
    {
        app('cache')->store('file')->flush();
    }

    /**
     * Los limitadores con nombre que registraron los `ServiceProvider`.
     *
     * Se leen de la propiedad protegida porque `RateLimiter` no ofrece un listado,
     * y un catalogo escrito aqui a mano se quedaria atras con la primera zona
     * nueva.
     *
     * @return array<string, \Closure>
     */
    private static function registeredLimiters(): array
    {
        $current = app(RateLimiter::class);

        /** @var array<string, \Closure> $limiters */
        $limiters = (fn (): array => $this->limiters)->call($current);

        return $limiters;
    }

    private static function assertRedisIsDown(): void
    {
        try {
            app('redis')->connection('default')->ping();
        } catch (\RedisException) {
            return;
        }

        throw new \LogicException('RedisOutage::begin() did not take Redis down: the test would not prove anything.');
    }
}
