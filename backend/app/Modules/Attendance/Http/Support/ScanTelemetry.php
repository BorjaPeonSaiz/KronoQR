<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza, la metrica y el log de un escaneo (doc 02 §8.1 y §8.2).
 *
 * ## Por que en el borde y no dentro del caso de uso
 *
 * Tres razones, y ninguna es de comodidad:
 *
 * 1. **El `trace_id` llega en una cabecera HTTP.** Propagarlo desde el `fetch`
 *    del navegador del quiosco hasta la consulta SQL —que es lo que el §8.1
 *    promete— exige leer `traceparent`, y eso solo existe aqui.
 * 2. **La duracion que importa es la del endpoint.** RNF-P-01 fija p95 < 150 ms
 *    para el fichaje *visto por el quiosco*, no para el metodo `handle()`.
 * 3. **El caso de uso se queda limpio.** `RegisterScanHandler` orquesta reglas;
 *    un `try/finally` de medicion alrededor de cada rama seria ruido en el sitio
 *    donde menos conviene tenerlo.
 *
 * ## Lo que el log NO lleva
 *
 * **Nunca el nombre del empleado** (regla dura 21). El log tecnico va a Loki y
 * el historico de errores viaja al fabricante dentro del paquete de diagnostico
 * (ADR-020): si lleva PII, se ha filtrado. Se identifica por `employee_uuid`,
 * `scan_id`, `device_id` y `trace_id`, que es lo que el §8.1 enumera y lo unico
 * que hace falta para reconstruir un problema.
 *
 * El `result` **si** va al log y a la metrica, con su causa concreta. No
 * contradice la regla dura 17: lo que RS-03 prohibe es revelarla **al cliente**,
 * y el escenario *QR falsificado* del doc 01 §11 exige justo lo contrario del
 * lado del servidor — «se incrementa el contador de escaneos rechazados por
 * firma».
 *
 * ## Medir no puede romper un fichaje
 *
 * Todo lo de esta clase esta envuelto para que un fallo de la capa de
 * observabilidad no convierta un fichaje correcto en un `500`. La regla dura 19
 * es tajante: el quiosco nunca bloquea al empleado, y perder una traza es
 * infinitamente mas barato que perder una jornada.
 */
final readonly class ScanTelemetry
{
    private const string SPAN_NAME = 'attendance.register_scan';

    public function __construct(
        private ScanMetrics $metrics,
        private LoggerInterface $logger,
    ) {}

    /**
     * Envuelve el procesamiento del escaneo.
     *
     * @param  callable(): RegisterScanResult  $process
     */
    public function measure(string $scanId, string $deviceUuid, callable $process): RegisterScanResult
    {
        $span = $this->startSpan($scanId, $deviceUuid);
        $startedAt = microtime(true);

        try {
            $result = $process();
        } catch (Throwable $failure) {
            $this->endSpan($span, 'error');

            throw $failure;
        }

        $seconds = microtime(true) - $startedAt;

        $this->metrics->scanProcessed($deviceUuid, $result->result, $seconds);
        $this->endSpan($span, $result->result->value);
        $this->log($result, $deviceUuid, $seconds, $span);

        return $result;
    }

    private function startSpan(string $scanId, string $deviceUuid): ?SpanInterface
    {
        try {
            // `Globals` devuelve un proveedor inerte mientras el SDK no este
            // configurado, asi que esto no cuesta nada en una instalacion que no
            // exporta trazas — que es la de la mayoria de los clientes.
            $span = Globals::tracerProvider()
                ->getTracer('kronoqr.attendance')
                ->spanBuilder(self::SPAN_NAME)
                ->setSpanKind(SpanKind::KIND_SERVER)
                // El contexto activo trae el `traceparent` que el middleware de
                // OpenTelemetry ya extrajo de la peticion, de modo que este span
                // cuelga del que abrio el navegador del quiosco.
                ->setParent(Context::getCurrent())
                ->setAttribute('scan.id', $scanId)
                ->setAttribute('device.id', $deviceUuid)
                ->startSpan();

            return $span;
        } catch (Throwable) {
            return null;
        }
    }

    private function endSpan(?SpanInterface $span, string $result): void
    {
        if (! $span instanceof SpanInterface) {
            return;
        }

        try {
            $span->setAttribute('scan.result', $result);
            $span->end();
        } catch (Throwable) {
            // Ver el docblock de la clase: medir no puede romper un fichaje.
        }
    }

    private function log(RegisterScanResult $result, string $deviceUuid, float $seconds, ?SpanInterface $span): void
    {
        $context = [
            'trace_id' => $this->traceIdOf($span),
            'scan_id' => $result->scanId,
            'device_id' => $deviceUuid,
            'employee_uuid' => $result->employeeUuid,
            'result' => $result->result->value,
            'replay' => $result->isReplay,
            'duration_ms' => (int) round($seconds * 1000),
        ];

        // Un rechazo es informacion operativa, no un fallo del sistema: sube a
        // `warning` para que aparezca en el cuadro de mando sin despertar a
        // nadie. Los rechazos legitimos existen todos los dias —una tarjeta
        // revocada que alguien sigue llevando en la cartera— y tratarlos como
        // errores acabaria silenciando el canal.
        if ($result->isRejected()) {
            $this->logger->warning('attendance.scan_rejected', $context);

            return;
        }

        $this->logger->info('attendance.scan_processed', $context);
    }

    private function traceIdOf(?SpanInterface $span): ?string
    {
        if (! $span instanceof SpanInterface) {
            return null;
        }

        $traceId = $span->getContext()->getTraceId();

        // Un `trace_id` a ceros es el que devuelve un span inerte: escribirlo
        // seria peor que no escribir nada, porque parece un identificador.
        return trim($traceId, '0') === '' ? null : $traceId;
    }
}
