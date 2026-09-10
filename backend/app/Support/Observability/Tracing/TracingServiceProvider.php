<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * Instrumentacion transversal de la tarea 3.1 (doc 02 §8.1): trazas. Vive fuera
 * de los modulos a proposito: es infraestructura de TODO el proceso, no de un
 * dominio.
 *
 * ## Sin destino no se arranca nada
 *
 * Con `OTEL_EXPORTER_OTLP_ENDPOINT` vacio —el valor de serie y el de la mayoria
 * de las instalaciones— este proveedor **no registra nada**: ni el SDK, ni el
 * oyente de consultas, ni el del planificador. `Globals` sigue devolviendo el
 * proveedor inerte y la instrumentacion que ya existe cuesta lo que costaba, que
 * es nada. Ver {@see TracerFactory}.
 *
 * ## El registro es PEREZOSO
 *
 * `Globals::registerInitializer()` en lugar de construir el `TracerProvider` en
 * el arranque: el SDK, su exportador y su cliente HTTP no se instancian hasta
 * que alguien pide de verdad un tracer. Una peticion que no abre ningun span
 * —`/api/v1/health`, la sonda que corre cada diez segundos— no paga por el SDK.
 *
 * ## Y el envio, EN CUATRO PUNTOS ELEGIDOS
 *
 * `BatchSpanProcessor` se construye con `autoFlush: false` (ver
 * {@see TracerFactory}), de modo que **nunca exporta por su cuenta a mitad de
 * nada**. El vaciado ocurre solo aqui, y son cuatro sitios:
 *
 * - **Al cerrar el proceso** (`ShutdownHandler`). En PHP-FPM eso ocurre
 *   **despues** de que el cliente tenga su respuesta, que es la unica forma de
 *   que exportar trazas no empeore el numero que la traza mide.
 * - **Al terminar cada trabajo de la cola** (`JobProcessed`, `JobFailed`). El
 *   *worker* es un proceso de vida larga: sin esto los spans se acumularian
 *   hasta que muriera, que puede ser mañana.
 * - **Al terminar cada comando** (`CommandFinished`). Mismo motivo para el
 *   planificador, que ejecuta varios comandos en un solo proceso.
 *
 * Es el mismo reparto que el buffer de log de `LoggingServiceProvider`, y por la
 * misma razon: lo que se acumula en memoria de un proceso largo tiene que
 * vaciarse en el limite de una unidad de trabajo, nunca dentro de ella.
 *
 * ## Nada de esto puede tumbar una peticion ni un trabajo
 *
 * Todo va envuelto (regla dura 15 y 19). Un colector caido, una URL mal escrita o
 * un `traceparent` malformado no pueden convertir un fichaje correcto en un
 * `500`.
 */
final class TracingServiceProvider extends ServiceProvider
{
    /**
     * Si el SDK ya se registro en ESTE proceso. Ver {@see self::registerSdk()}.
     */
    private static bool $sdkRegistered = false;

    /**
     * El proveedor construido en ESTE proceso, para poder vaciarlo.
     *
     * Se guarda aqui y no se pide a `Globals` porque lo que hace falta es el
     * `TracerProvider` del SDK —el unico que sabe vaciar un lote—, y `Globals`
     * devuelve la interfaz de la API, que no tiene `forceFlush()`.
     */
    private static ?TracerProvider $provider = null;

    public function register(): void
    {
        $this->app->singleton(TracerFactory::class, static function (): TracerFactory {
            $config = config();

            return new TracerFactory(
                $config->string('tracing.endpoint'),
                $config->string('tracing.service_name'),
                // La version desplegada, resuelta al cargar la configuracion
                // (App\Support\Version\DeployedVersion): la misma que publica
                // `GET /api/v1/health`, para poder correlacionar un incidente con
                // una version concreta (doc 02 §10.5).
                $config->string('app.version'),
                $config->string('app.env'),
                $config->float('tracing.sampler_ratio', 1.0),
                $config->float('tracing.timeout_seconds', 2.0),
            );
        });

        $this->app->singleton(QueuedTraceContext::class);
        $this->app->singleton(ScheduledTaskSpans::class);
        $this->app->singleton(DatabaseSpans::class);
    }

    public function boot(): void
    {
        try {
            $factory = $this->app->make(TracerFactory::class);

            if (! $factory->enabled()) {
                return;
            }

            $this->registerSdk($factory);
            $this->listenToQueue();
            $this->listenToScheduler();
            $this->listenToDatabase();
            $this->flushOnWorkUnitBoundaries();
        } catch (Throwable) {
            // Ver el docblock: arrancar las trazas nunca puede impedir arrancar
            // la aplicacion.
        }
    }

    /**
     * El SDK, construido la primera vez que alguien pida un tracer.
     *
     * **Una sola vez por PROCESO, no por arranque de la aplicacion.**
     * `Globals` guarda sus inicializadores en una propiedad estatica y no los
     * sustituye: los acumula. En produccion la aplicacion arranca una vez y da
     * igual, pero la suite de pruebas construye la aplicacion miles de veces en
     * el mismo proceso, y sin esta bandera cada prueba dejaria ahi otro
     * inicializador — hasta que el primero que pidiera un tracer construyera
     * miles de `TracerProvider`, con su exportador y su cliente HTTP cada uno.
     */
    private function registerSdk(TracerFactory $factory): void
    {
        if (self::$sdkRegistered) {
            return;
        }

        self::$sdkRegistered = true;

        Globals::registerInitializer(static function (Configurator $configurator) use ($factory): Configurator {
            $provider = $factory->build();

            if (! $provider instanceof TracerProvider) {
                return $configurator;
            }

            self::$provider = $provider;

            // Vaciar el lote al cerrar el proceso. Sin esto, en PHP-FPM los spans
            // de una peticion se quedarian en la cola del `BatchSpanProcessor` y
            // moririan con el proceso — y con `autoFlush: false` no hay ninguna
            // otra via automatica, que es justo lo que se quiere.
            ShutdownHandler::register($provider->shutdown(...));

            return $configurator
                ->withTracerProvider($provider)
                ->withPropagator(TraceContextPropagator::getInstance());
        });
    }

    /**
     * El vaciado explicito en el limite de cada unidad de trabajo.
     *
     * Sin esto y con `autoFlush: false`, un *worker* de Horizon acumularia los
     * spans de todos sus trabajos hasta morir. Con `autoFlush: true` —lo que
     * habia— el exportador aparecia DENTRO de un trabajo, que es peor.
     *
     * Se resuelve `Globals::tracerProvider()` primero a proposito: es lo que
     * dispara el inicializador perezoso y deja `self::$provider` puesto. Si nadie
     * abrio un span en toda la unidad de trabajo, no hay nada que vaciar y
     * tampoco se construye nada.
     */
    private function flushOnWorkUnitBoundaries(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ([JobProcessed::class, JobFailed::class, CommandFinished::class] as $event) {
            $events->listen($event, static fn () => self::flush());
        }
    }

    /** Nunca lanza: un fallo del colector no marca un trabajo como fallido. */
    private static function flush(): void
    {
        try {
            self::$provider?->forceFlush();
        } catch (Throwable) {
            // Ver el docblock de la clase (regla dura 15 y 19).
        }
    }

    /**
     * Devuelve el proveedor al estado «sin registrar», para las pruebas que
     * montan su propio SDK.
     *
     * `Globals::reset()` borra los inicializadores acumulados, pero la bandera
     * estatica de esta clase no se enteraba: a partir de la primera prueba que
     * llamara a `reset()`, el proveedor **no volvia a registrar nada** y el resto
     * de la suite corria con instrumentacion inerte sin que ninguna prueba lo
     * dijera. Lo llaman `RecordingTracer::uninstall()` y `UnreachableCollector`.
     */
    public static function forgetRegistration(): void
    {
        self::$sdkRegistered = false;
        self::$provider = null;
    }

    /**
     * La traza cruza la cola por el `Context` de Laravel. Ver
     * {@see QueuedTraceContext}.
     */
    private function listenToQueue(): void
    {
        $queued = $this->app->make(QueuedTraceContext::class);

        Context::dehydrating(static fn (ContextRepository $context) => $queued->dehydrating($context));
        Context::hydrated(static fn (ContextRepository $context) => $queued->hydrated($context));

        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobProcessed::class, static fn () => $queued->release());
        $events->listen(JobFailed::class, static fn () => $queued->release());
    }

    private function listenToScheduler(): void
    {
        $spans = $this->app->make(ScheduledTaskSpans::class);
        $events = $this->app->make(Dispatcher::class);

        $events->listen(ScheduledTaskStarting::class, static fn (ScheduledTaskStarting $event) => $spans->starting($event));
        $events->listen(ScheduledTaskFinished::class, static fn (ScheduledTaskFinished $event) => $spans->finished($event));
        $events->listen(ScheduledTaskSkipped::class, static fn (ScheduledTaskSkipped $event) => $spans->skipped($event));
        $events->listen(ScheduledTaskFailed::class, static fn (ScheduledTaskFailed $event) => $spans->failed($event));
    }

    private function listenToDatabase(): void
    {
        $spans = $this->app->make(DatabaseSpans::class);

        $this->app->make(Dispatcher::class)
            ->listen(QueryExecuted::class, static fn (QueryExecuted $event) => $spans->handle($event));
    }
}
