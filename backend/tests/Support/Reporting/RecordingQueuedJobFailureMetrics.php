<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\QueuedJobFailureMetrics;

/**
 * Recuerda los fallos de trabajo contados, en lugar de escribirlos en Redis.
 *
 * `queue_jobs_failed_total{job}` solo se mueve sola cuando el trabajo **deja
 * salir** su excepcion, y `GenerateReportExportJob` la captura a proposito.
 * Comprobar contra Redis obligaria a leer una clave de una base compartida por
 * toda la suite; con este doble, la afirmacion es directa: «una generacion que
 * falla cuenta un fallo, y con la etiqueta correcta».
 */
final class RecordingQueuedJobFailureMetrics implements QueuedJobFailureMetrics
{
    /** @var list<string> */
    public array $failures = [];

    public function failed(string $job): void
    {
        $this->failures[] = $job;
    }
}
