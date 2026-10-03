<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * Donde se guardan los avisos de fichaje descartado (RN-22, ADR-047):
 * `discarded_scan_reports`.
 *
 * **Idempotente por `scan_id`** (regla dura 8): el UNIQUE de la tabla decide, sin
 * ningun `SELECT` previo. Diez avisos simultaneos del mismo `scan_id` dejan una
 * fila. `already_recorded` lo calcula la misma sentencia, con una subconsulta a
 * `scan_events`.
 */
interface DiscardedScanReportLog
{
    /**
     * @return bool `true` si escribio la fila; `false` si ese `scan_id` ya
     *              estaba avisado.
     */
    public function record(DiscardedScanReportRecord $report): bool;
}
