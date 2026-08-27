<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ScanResult;

/**
 * Doble del puerto `ScanMetrics` que recuerda lo medido.
 *
 * Es la segunda implementacion que justifica el puerto (doc 02 §3.5) y la unica
 * forma de afirmar sobre `scans_total{device,result}` sin levantar Prometheus.
 *
 * Importa porque el escenario *QR falsificado* del doc 01 §11 termina en «y se
 * incrementa el contador de escaneos rechazados por firma»: sin un doble que
 * cuente, esa linea del criterio de aceptacion no se puede comprobar.
 */
final class RecordingScanMetrics implements ScanMetrics
{
    /** @var list<array{device: string, result: ScanResult, seconds: float}> */
    public array $observations = [];

    /**
     * Lotes sincronizados (`sync_delay_seconds`, tarea 1.7).
     *
     * @var list<array{device: string, size: int, delay: int}>
     */
    public array $batches = [];

    public function scanProcessed(string $deviceUuid, ScanResult $result, float $durationSeconds): void
    {
        $this->observations[] = [
            'device' => $deviceUuid,
            'result' => $result,
            'seconds' => $durationSeconds,
        ];
    }

    public function batchSynchronised(string $deviceUuid, int $size, int $delaySeconds): void
    {
        $this->batches[] = [
            'device' => $deviceUuid,
            'size' => $size,
            'delay' => $delaySeconds,
        ];
    }

    /**
     * Cuantas veces se conto ese desenlace.
     */
    public function countOf(ScanResult $result): int
    {
        $count = 0;

        foreach ($this->observations as $observation) {
            if ($observation['result'] === $result) {
                $count++;
            }
        }

        return $count;
    }
}
