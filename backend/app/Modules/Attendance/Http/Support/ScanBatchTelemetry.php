<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\UseCase\ScanBatchOutcome;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de una sincronizacion de lote (doc 02 §8.1).
 *
 * ## Un span del lote, no cincuenta
 *
 * Los contadores por escaneo —`scans_total`, `scan_processing_duration_seconds`—
 * los emite el caso de uso, que es quien mide cada elemento. Lo que falta y solo
 * existe aqui es el **span de la peticion**, que cuelga del `traceparent` que
 * envio el navegador del quiosco (§8.1) y permite ver un drenaje completo como
 * una sola cosa: cuantos elementos traia, cuanto tardo y cuantos quedaron sin
 * procesar.
 *
 * Abrir un span por elemento habria multiplicado por cincuenta el volumen de
 * trazas del peor momento del dia —la reconexion despues de un corte— para contar
 * lo que ya cuenta el histograma.
 *
 * ## Lo que el log NO lleva
 *
 * Ni nombres, ni `qr_payload`, ni huellas de tarjeta (regla dura 21). Se
 * identifica por `device_id`, `trace_id` y el tamano del lote. El `scan_id` de
 * cada elemento aparece solo cuando algo falla, y lo escribe el caso de uso.
 *
 * ## Medir no puede romper una sincronizacion
 *
 * Mismo criterio que {@see ScanTelemetry} y mismo andamiaje, {@see SpanScope}:
 * todo va envuelto para que un fallo de la capa de observabilidad no convierta un
 * lote correcto en un `500`. Perder una traza es infinitamente mas barato que
 * devolver un error por cincuenta fichajes que ya estan escritos.
 */
final readonly class ScanBatchTelemetry
{
    private const string SPAN_NAME = 'attendance.sync_scan_batch';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): list<ScanBatchOutcome>  $process
     * @return list<ScanBatchOutcome>
     */
    public function measure(ScanBatch $batch, callable $process): array
    {
        $deviceUuid = $batch->earliest()->deviceUuid;
        // El contexto activo trae el `traceparent` que el middleware de
        // propagacion extrajo de la peticion, de modo que este span cuelga del
        // que abrio el navegador del quiosco (§8.1). Lo hace {@see SpanScope}.
        $span = SpanScope::start('kronoqr.attendance', self::SPAN_NAME, SpanKind::KIND_SERVER, [
            'scan_batch.size' => $batch->size(),
            'device.id' => $deviceUuid,
        ]);
        $startedAt = microtime(true);

        try {
            $outcomes = $process();
        } catch (Throwable $failure) {
            $span->end(['scan_batch.not_processed' => 0]);

            throw $failure;
        }

        $pending = 0;

        foreach ($outcomes as $outcome) {
            if (! $outcome->wasProcessed()) {
                $pending++;
            }
        }

        $span->end(['scan_batch.not_processed' => $pending]);
        $this->log($batch, $deviceUuid, $pending, microtime(true) - $startedAt, $span);

        return $outcomes;
    }

    private function log(ScanBatch $batch, string $deviceUuid, int $pending, float $seconds, SpanScope $span): void
    {
        $context = [
            'trace_id' => $span->traceId(),
            'device_id' => $deviceUuid,
            'batch_size' => $batch->size(),
            'not_processed' => $pending,
            'oldest_occurred_at' => $batch->earliest()->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'duration_ms' => (int) round($seconds * 1000),
        ];

        // Un lote con elementos sin procesar es una averia del servidor, no una
        // incidencia de negocio: sube a `warning` para que se vea sin despertar a
        // nadie por un lote suelto. El detalle de cada elemento lo escribio ya el
        // caso de uso con nivel `error`.
        if ($pending > 0) {
            $this->logger->warning('attendance.batch_partially_processed', $context);

            return;
        }

        $this->logger->info('attendance.batch_synchronised', $context);
    }
}
