<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\UseCase\ScanBatchOutcome;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Illuminate\Support\Facades\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El log de una sincronizacion de lote (doc 02 §8.1).
 *
 * ## Un span del lote, no cincuenta
 *
 * Los contadores por escaneo —`scans_total`, `scan_processing_duration_seconds`—
 * los emite el caso de uso, que es quien mide cada elemento. El span del lote y
 * el de cada escaneo los abre {@see UseCaseInstrumentation} en los casos de uso
 * (V1-RV-03): `attendance.sync_scan_batch` con un `attendance.register_scan` por
 * elemento, que es lo que ya cuenta el histograma y lo que ya producen sus
 * consultas. Lo que solo existe aqui es lo de la **peticion**: el tamaño del
 * lote y el `device.id` se añaden al span de servidor, que cuelga del
 * `traceparent` del quiosco (§8.1), junto con cuantos quedaron sin procesar.
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
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): list<ScanBatchOutcome>  $process
     * @return list<ScanBatchOutcome>
     */
    public function measure(ScanBatch $batch, callable $process): array
    {
        $deviceUuid = $batch->earliest()->deviceUuid;
        // Al span de servidor de la peticion, que cuelga del `traceparent` del
        // quiosco (§8.1).
        SpanScope::annotateCurrent([
            'scan_batch.size' => $batch->size(),
            'device.id' => $deviceUuid,
        ]);
        // Y en el `Context` de Laravel, para que lo lleve TODA linea de log de
        // esta peticion, no solo las de aqui (tarea 3.1, decision 9).
        $this->correlate($deviceUuid);

        $startedAt = microtime(true);

        $outcomes = $process();

        $pending = 0;
        $heldBack = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome->wasHeldBack()) {
                $heldBack++;
            } elseif (! $outcome->wasProcessed()) {
                $pending++;
            }
        }

        SpanScope::annotateCurrent(['scan_batch.not_processed' => $pending, 'scan_batch.held_back' => $heldBack]);
        $this->log($batch, $deviceUuid, $pending, $heldBack, microtime(true) - $startedAt);

        return $outcomes;
    }

    /**
     * El identificador del dispositivo en el contexto de la peticion. **Opaco y
     * nada mas**: nunca un nombre (regla dura 21).
     */
    private function correlate(string $deviceUuid): void
    {
        try {
            if ($deviceUuid !== '') {
                Context::add('device_id', $deviceUuid);
            }
        } catch (Throwable) {
            // Correlacionar una sincronizacion no puede impedirla (regla dura 19).
        }
    }

    private function log(ScanBatch $batch, string $deviceUuid, int $pending, int $heldBack, float $seconds): void
    {
        $context = [
            'trace_id' => SpanScope::currentTraceId(),
            'device_id' => $deviceUuid,
            'batch_size' => $batch->size(),
            'not_processed' => $pending,
            // RN-21: los que se aplazaron detras del no procesado, sin mirarlos.
            'held_back' => $heldBack,
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
