<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Throwable;

/**
 * Arranca el SDK de OpenTelemetry **solo si hay a donde exportar** (doc 02 §8.1,
 * decision 5 de la ficha 3.1).
 *
 * ## Sin endpoint no se construye nada
 *
 * `OTEL_EXPORTER_OTLP_ENDPOINT` vacio es el valor de serie y el de la mayoria de
 * las instalaciones. En ese caso {@see self::build()} devuelve `null`, nadie
 * registra un proveedor y `Globals::tracerProvider()` sigue devolviendo el
 * inerte: las dieciocho clases `*Telemetry` y {@see SafeSpan} siguen ejecutando
 * su codigo, pero cada span es un objeto que no reserva memoria ni sale a la red.
 * El coste de la observabilidad para quien no la usa es cero, y eso es una
 * decision, no una consecuencia.
 *
 * ## Por lotes, con tiempos cortos, SIN REINTENTOS y SIN `autoFlush`
 *
 * `BatchSpanProcessor` acumula los spans y los envia **cuando se le pide**, no
 * cuando le parece. `autoFlush: false` es la diferencia y no es un detalle:
 *
 * Con el valor de serie (`true`), el procesador exporta **de forma sincrona
 * dentro del `onEnd()` de un span** en cuanto han pasado los 5 s del retardo
 * programado desde el primero. En PHP-FPM eso no se nota —los spans de una
 * peticion caben de sobra en 5 s y el vaciado ocurre al cerrar—, pero en un
 * *worker* de Horizon o en un comando del planificador, que son procesos de vida
 * larga, significa que **cerrar un span cualquiera se convierte sin aviso en un
 * `export()->await()`**: el trabajo paga hasta el tiempo maximo del colector,
 * dentro de su propia transaccion, en el punto en que casualmente cerrara un
 * span. La regla dura 15 dice que la instrumentacion no abre un camino en el que
 * el producto espere al exportador; con `autoFlush` lo abria.
 *
 * Apagado, el vaciado ocurre solo donde {@see TracingServiceProvider} lo pide,
 * que son cuatro puntos elegidos: al terminar cada trabajo de la cola
 * (`JobProcessed`/`JobFailed`), al terminar cada comando (`CommandFinished`), y
 * al cerrar el proceso (`ShutdownHandler`) — que en PHP-FPM es despues de que el
 * cliente tenga su respuesta.
 *
 * El transporte lleva ademas `maxRetries = 0` a proposito: el valor de serie del
 * SDK son **tres reintentos con espera creciente**, que en un colector caido son
 * tres viajes y varios cientos de milisegundos de un proceso de PHP-FPM que hace
 * falta para el siguiente fichaje. Un colector que no responde tiene que costar
 * un intento fallido y nada mas (regla dura 15 y 19).
 *
 * Cualquier fallo de construccion —una URL mal escrita, un cliente HTTP que no
 * se puede descubrir, la extension de protobuf ausente— devuelve `null` y deja
 * la aplicacion exactamente como estaba. **Nunca se propaga**: una instalacion no
 * puede quedarse sin fichar porque el colector de trazas este mal configurado.
 *
 * ## El muestreo respeta al cliente
 *
 * `ParentBased` sobre `TraceIdRatioBased`: si el `traceparent` que envio el
 * quiosco dice que la traza esta muestreada, se respeta; solo se decide por
 * proporcion cuando la traza empieza aqui. Decidir de nuevo en el servidor
 * partiria la traza justo donde empieza a servir.
 */
final readonly class TracerFactory
{
    /**
     * @param  string  $endpoint  Raiz del colector OTLP/HTTP. Vacio desactiva la exportacion.
     * @param  string  $serviceName  `service.name` del recurso.
     * @param  string  $serviceVersion  `service.version`: la version desplegada, para poder correlacionar un incidente con una version concreta (doc 02 §10.5).
     * @param  string  $environment  `deployment.environment.name`.
     * @param  float  $samplerRatio  Proporcion de 0.0 a 1.0.
     * @param  float  $timeoutSeconds  Tiempo maximo del envio al colector.
     */
    public function __construct(
        private string $endpoint,
        private string $serviceName,
        private string $serviceVersion,
        private string $environment,
        private float $samplerRatio,
        private float $timeoutSeconds,
    ) {}

    /** Si esta instalacion tiene un destino declarado para sus trazas. */
    public function enabled(): bool
    {
        return trim($this->endpoint) !== '';
    }

    /**
     * El proveedor de trazas, o `null` si no hay destino o si construirlo falla.
     */
    public function build(): ?TracerProvider
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $transport = (new OtlpHttpTransportFactory)->create(
                HttpEndpointResolver::create()->resolveToString(trim($this->endpoint), Signals::TRACE),
                ContentTypes::PROTOBUF,
                [],
                null,
                max($this->timeoutSeconds, 0.1),
                100,
                // Ver el docblock: un colector caido cuesta un intento, no cuatro.
                0,
            );

            return new TracerProvider(
                new BatchSpanProcessor(
                    new SpanExporter($transport),
                    ClockFactory::getDefault(),
                    // Ver el docblock: sin `autoFlush`, el envio no puede
                    // aparecer dentro de un trabajo de la cola ni de un comando.
                    autoFlush: false,
                ),
                $this->sampler(),
                $this->resource(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function sampler(): SamplerInterface
    {
        // A 1.0 no se instancia el muestreador por proporcion: `AlwaysOn` no
        // calcula nada por span, y 1.0 es el valor de serie.
        if ($this->samplerRatio >= 1.0) {
            return new ParentBased(new AlwaysOnSampler);
        }

        return new ParentBased(new TraceIdRatioBasedSampler(max($this->samplerRatio, 0.0)));
    }

    /**
     * El recurso: quien dice ser este proceso.
     *
     * Sobre el recurso por omision del SDK —que aporta `telemetry.sdk.*` y lo que
     * declare `OTEL_RESOURCE_ATTRIBUTES`— se imponen los tres que el producto
     * garantiza. **Nada de esto identifica al cliente**: el nombre del servicio es
     * del producto, no del hotel (regla dura 13 y 21).
     */
    private function resource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $this->serviceName,
            ResourceAttributes::SERVICE_VERSION => $this->serviceVersion,
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => $this->environment,
        ])));
    }
}
