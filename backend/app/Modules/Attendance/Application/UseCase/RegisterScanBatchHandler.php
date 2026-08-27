<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sincroniza la cola offline del quiosco (RF-KI-04, doc 02 §6).
 *
 * **No es un caso de uso nuevo: es `RegisterScan` repetido.** Aqui no hay ni una
 * regla de negocio; quien decide cada escaneo es {@see RegisterScanHandler}, el
 * mismo que atiende `POST /api/v1/scan`. Este orquestador solo aporta tres cosas
 * que un bucle en el controlador no daria: el orden, el aislamiento entre
 * elementos y la medida del retraso.
 *
 * ## Una transaccion por elemento, no una por lote
 *
 * Es deliberado y es lo contrario de lo que suele pedir la regla «un caso de uso,
 * una transaccion»: **cada escaneo es su propio caso de uso**. Con una sola
 * transaccion para los cincuenta, el rechazo de una tarjeta revocada —o una
 * carrera perdida— revertiria los fichajes ya registrados de otras personas, que
 * es exactamente lo que la regla dura 19 prohibe. La transaccion la abre
 * `RegisterScanHandler` para cada elemento, con su proyeccion y su auditoria
 * dentro (RN-06, regla dura 6).
 *
 * ## El orden lo trae el lote, no este bucle
 *
 * `ScanBatch` llega ya ordenado por `occurred_at` (doc 02 §6). Aqui se recorre y
 * ya esta: si el orden se decidiera en este metodo, no habria forma de probarlo
 * sin base de datos, y es la comprobacion unitaria que exige el §9.5.
 *
 * ## Un elemento que revienta no se lleva el lote
 *
 * Cualquier `Throwable` de un elemento se registra y se devuelve como **no
 * procesado**, que es lo unico honesto: el servidor no decidio nada sobre ese
 * escaneo, asi que el quiosco tiene que conservarlo. Convertirlo en rechazo lo
 * sacaria de la cola y perderia una jornada; dejarlo escapar abortaria el envio
 * entero. Ver {@see ScanBatchOutcome}.
 *
 * **El fallo no se silencia**: sube a `error` con `scan_id` y `device_id` —jamas
 * el nombre de nadie (regla dura 21)— porque un lote con elementos no procesados
 * de forma repetida es una averia, no una incidencia de negocio.
 *
 * ## Instrumentacion
 *
 * Cada elemento incrementa `scans_total{device,result}` y observa
 * `scan_processing_duration_seconds`, igual que un escaneo suelto: un fichaje que
 * llega por el lote cuenta lo mismo que uno que llega solo, y si no se contara
 * aqui, la metrica del cambio de turno se desplomaria justo despues de un corte
 * de red — cuando mas se mira. El lote añade ademas `sync_delay_seconds`, que es
 * lo que mide cuanto tiempo estuvo la cola sin drenar (§8.2).
 */
final readonly class RegisterScanBatchHandler
{
    public function __construct(
        private RegisterScanHandler $scans,
        private ScanMetrics $metrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<ScanBatchOutcome> En el mismo orden en que se procesaron, es
     *                                decir, por `occurred_at` ascendente.
     */
    public function handle(ScanBatch $batch): array
    {
        $this->measureSyncDelay($batch);

        $outcomes = [];

        foreach ($batch->scans as $scan) {
            $outcomes[] = $this->process($scan);
        }

        return $outcomes;
    }

    private function process(RegisterScanCommand $scan): ScanBatchOutcome
    {
        $startedAt = microtime(true);

        try {
            $result = $this->scans->handle($scan);
        } catch (Throwable $failure) {
            $this->logger->error('attendance.batch_scan_failed', [
                'scan_id' => $scan->scanId,
                'device_id' => $scan->deviceUuid,
                // La clase y el mensaje, no la traza: esto viaja a Loki y de ahi
                // al paquete de diagnostico (ADR-020), y una traza lleva rutas y
                // argumentos que pueden contener el payload del QR.
                'exception' => $failure::class,
            ]);

            return ScanBatchOutcome::notProcessed($scan->scanId);
        }

        $this->metrics->scanProcessed($scan->deviceUuid, $result->result, microtime(true) - $startedAt);

        return ScanBatchOutcome::processed($result);
    }

    /**
     * Cuanto tiempo lleva sin drenar la cola: `now - occurred_at` del elemento
     * mas antiguo (§8.2, `sync_delay_seconds{device}`).
     *
     * Se mide **antes** de procesar y no despues, porque lo que interesa es el
     * retraso con el que el fichaje llego, no lo que tardo el servidor en
     * atenderlo. Es la senal que en la Fase 2 abre la incidencia de RN-15; aqui
     * solo se publica.
     *
     * Nunca es negativa: un reloj de tablet adelantado produciria un retraso con
     * signo, y una duracion negativa en un histograma de Prometheus no significa
     * nada. El desfase de reloj se registra aparte, escaneo a escaneo, en
     * `scan_events.clock_skew_seconds` (RF-AT-10).
     */
    private function measureSyncDelay(ScanBatch $batch): void
    {
        $earliest = $batch->earliest();

        $delay = $this->clock->now()->getTimestamp() - $earliest->occurredAt->getTimestamp();

        $this->metrics->batchSynchronised($earliest->deviceUuid, $batch->size(), max(0, $delay));
    }
}
