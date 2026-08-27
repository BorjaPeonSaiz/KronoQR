<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
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
 * De eso se encarga {@see SpanScope}, que envuelve el andamiaje del span para
 * que un fallo de la capa de observabilidad no convierta un fichaje correcto en
 * un `500`. La regla dura 19 es tajante: el quiosco nunca bloquea al empleado, y
 * perder una traza es infinitamente mas barato que perder una jornada. Lo que se
 * queda aqui son los atributos, el nivel del log y que puede y que no puede
 * escribirse en el, que es lo que distingue a esta telemetria de las demas.
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
        $span = SpanScope::start('kronoqr.attendance', self::SPAN_NAME, SpanKind::KIND_SERVER, [
            'scan.id' => $scanId,
            'device.id' => $deviceUuid,
        ]);
        $startedAt = microtime(true);

        try {
            $result = $process();
        } catch (Throwable $failure) {
            $span->end(['scan.result' => 'error']);

            throw $failure;
        }

        $seconds = microtime(true) - $startedAt;

        $this->metrics->scanProcessed($deviceUuid, $result->result, $seconds);
        $span->end(['scan.result' => $result->result->value]);
        $this->log($result, $deviceUuid, $seconds, $span);

        return $result;
    }

    private function log(RegisterScanResult $result, string $deviceUuid, float $seconds, SpanScope $span): void
    {
        $context = [
            'trace_id' => $span->traceId(),
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
}
