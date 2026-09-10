<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use App\Http\Controllers\MetricsController;
use App\Http\Middleware\RestrictToMetricsNetwork;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Instrumentacion transversal de la tarea 3.1 (doc 02 §8.1): metricas. Vive
 * fuera de los modulos a proposito: es infraestructura de TODO el proceso, no
 * de un dominio.
 *
 * Aqui se cosen las tres cosas que no tienen dueño en ningun modulo:
 *
 * 1. **La ruta `GET /metrics`**, fuera de `/api/v1` y con su unica guarda
 *    ({@see RestrictToMetricsNetwork}). Se declara aqui y no en
 *    `bootstrap/app.php` porque este producto **no tiene rutas `web`**: las tres
 *    aplicaciones cliente son SPA servidas por Nginx y el backend solo expone
 *    API. Abrir un fichero de rutas `web` entero para una sola ruta de
 *    infraestructura habria traido de vuelta la sesion y el CSRF, que en un
 *    scrape no pintan nada.
 * 2. **Las series de las colas**, desde los eventos del *worker*.
 * 3. **La serie de las consultas**, acumulada durante la peticion o el trabajo y
 *    volcada una sola vez al terminar.
 *
 * ## Los oyentes se registran siempre, tambien en pruebas
 *
 * Son baratos —un `microtime()` y una suma en un array— y no tocan Redis hasta
 * el volcado. Apagarlos por entorno haria que la unica forma de saber si
 * funcionan fuera mirarlos en produccion.
 *
 * ## Nada de esto puede tumbar una peticion ni un trabajo
 *
 * Cada oyente envuelve su propio trabajo (regla dura 19). El registro en si
 * tambien: un contenedor sin `QueryExecuted` disponible —la consola de
 * `artisan` mas basica— no debe impedir que arranque la aplicacion.
 */
final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons y no `bind`: los dos acumuladores guardan estado entre
        // eventos —la marca de inicio de un trabajo, las consultas de la
        // peticion en curso—, y una instancia nueva por resolucion perderia
        // exactamente eso.
        // El prefijo global del cliente de Redis se resuelve AQUI y viaja por
        // constructor: `phpredis` no lo aplica al patron `MATCH` de un `SCAN`,
        // asi que el lector tiene que ponerlo a mano. Ver el docblock de
        // {@see RedisMetricReader::__construct()}.
        $this->app->singleton(RedisMetricReader::class, static fn (Application $app): RedisMetricReader => new RedisMetricReader(
            $app->make(Redis::class),
            self::keyPrefix(),
        ));

        $this->app->singleton(RedisMetricWriter::class);
        $this->app->singleton(QueueJobMetrics::class);
        $this->app->singleton(DatabaseQueryMetrics::class);
    }

    public function boot(): void
    {
        $this->registerRoute();
        $this->listenToQueue();
        $this->listenToDatabase();
    }

    /**
     * El prefijo global que el cliente de Redis antepone a toda clave.
     *
     * Vacio si no hay ninguno configurado: una instalacion sin prefijo es
     * legitima y el lector tiene que funcionar igual.
     */
    private static function keyPrefix(): string
    {
        $prefix = config('database.redis.options.prefix');

        return \is_string($prefix) ? $prefix : '';
    }

    /**
     * `GET /metrics`, con el middleware de red **solo en esta ruta**.
     */
    private function registerRoute(): void
    {
        Route::middleware(RestrictToMetricsNetwork::class)
            ->get('/metrics', MetricsController::class)
            ->name('metrics');
    }

    private function listenToQueue(): void
    {
        Event::listen(JobProcessing::class, [QueueJobMetrics::class, 'starting']);

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->app->make(QueueJobMetrics::class)->processed($event);
            // El volcado de las consultas del trabajo va aqui y no en
            // `terminating`: el *worker* es un proceso de larga vida que atiende
            // muchos trabajos, y esperar a que termine acumularia la jornada
            // entera en memoria para escribirla de golpe al reiniciarse.
            $this->app->make(DatabaseQueryMetrics::class)->flush();
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->app->make(QueueJobMetrics::class)->failed($event);
            $this->app->make(DatabaseQueryMetrics::class)->flush();
        });
    }

    private function listenToDatabase(): void
    {
        Event::listen(QueryExecuted::class, [DatabaseQueryMetrics::class, 'record']);

        // Despues de que el cliente tenga su respuesta —`Kernel::terminate()`
        // llama a `Application::terminate()`—, igual que `RecordHttpMetrics`
        // mide en su `terminate()`. Instrumentar no debe empeorar el numero que
        // se instrumenta.
        $this->app->terminating(function (): void {
            $this->app->make(DatabaseQueryMetrics::class)->flush();
        });
    }
}
