<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Monolog\Level;
use Throwable;

/**
 * Instrumentacion transversal de la tarea 3.1 (doc 02 §8.1): logs. Vive fuera de
 * los modulos a proposito: es infraestructura de TODO el proceso, no de un
 * dominio.
 *
 * ## Que registra
 *
 * El canal `loki` y **cuando se vacia su buffer**. La correlacion —`trace_id`,
 * `scan_id`, `device_id`, `employee_uuid` en toda linea— la engancha
 * `config/logging.php` como *tap* de cada canal, no este proveedor: asi vale
 * tambien para el canal que alguien anada manana sin tocar codigo.
 *
 * ## Los tres momentos en que se vacia, y por que son tres
 *
 * - **`terminating()` de la aplicacion**, que en PHP-FPM ocurre despues de que el
 *   cliente tenga su respuesta. Es el caso normal de una peticion.
 * - **Fin de cada trabajo de la cola** (`JobProcessed`, `JobFailed`). El worker es
 *   un proceso de vida larga: sin esto, las lineas de un trabajo se quedarian en
 *   memoria hasta que el proceso muriera, que puede ser mañana.
 * - **Fin de cada comando** (`CommandFinished`). Mismo motivo para el
 *   planificador, que ejecuta varios comandos en un solo proceso.
 *
 * ## Nada de esto se construye si no hay `LOKI_URL`
 *
 * El handler es un singleton perezoso y los oyentes preguntan primero si alguien
 * lo resolvio. En la instalacion que no usa Loki —el canal no entra en la pila—
 * el coste es una comprobacion de bandera por trabajo y por comando.
 */
final class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Enlace por clase y no por fabrica a proposito: asi este proveedor **no
         * nombra el cliente HTTP de Laravel**, que es lo que
         * `OutboundChannelsTest` vigila en todo el armazon (ADR-020, regla dura
         * 16). El contenedor resuelve la fabrica desde el tipo del constructor de
         * {@see HttpLokiTransport}, que es el unico fichero exceptuado y el unico
         * que abre la conexion — hacia el Loki del propio cliente.
         */
        $this->app->singleton(LokiTransport::class, HttpLokiTransport::class);

        $this->app->singleton(LokiHandler::class, static function (Application $app): LokiHandler {
            $config = config();

            return new LokiHandler(
                $app->make(LokiTransport::class),
                $config->string('logging.channels.loki.url'),
                $config->string('logging.channels.loki.service'),
                $config->string('logging.channels.loki.environment'),
                $config->float('logging.channels.loki.timeout', 1.0),
                $config->integer('logging.channels.loki.buffer_size', 1000),
                self::levelOf($config->string('logging.channels.loki.level', 'debug')),
            );
        });

        $this->app->singleton(LokiLogChannel::class, static fn (Application $app): LokiLogChannel => new LokiLogChannel(
            $app->make(LokiHandler::class),
        ));
    }

    public function boot(): void
    {
        $this->app->terminating(fn () => $this->flush());

        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobProcessed::class, fn () => $this->flush());
        $events->listen(JobFailed::class, fn () => $this->flush());
        $events->listen(CommandFinished::class, fn () => $this->flush());
    }

    /**
     * `LOG_LEVEL` traducido a un nivel de Monolog.
     *
     * Se traduce a mano en lugar de con `Level::fromName()` porque esa firma
     * exige una de sus veintitantas cadenas exactas y aqui llega lo que ponga un
     * `.env`. Un nivel mal escrito no puede tirar el arranque: se cae a `debug`,
     * que como mucho manda de mas.
     */
    private static function levelOf(string $level): Level
    {
        return match (strtolower(trim($level))) {
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Debug,
        };
    }

    /**
     * Vacia el buffer **solo si alguien llego a usar el canal**: resolverlo aqui
     * para descubrir que esta vacio construiria el handler y su cliente HTTP en
     * cada trabajo de cada instalacion, use Loki o no.
     */
    private function flush(): void
    {
        try {
            if (! $this->app->resolved(LokiHandler::class)) {
                return;
            }

            $this->app->make(LokiHandler::class)->flush();
        } catch (Throwable) {
            // Un fallo al enviar el log no puede ser el motivo de que un trabajo
            // se marque como fallido (regla dura 19).
        }
    }
}
