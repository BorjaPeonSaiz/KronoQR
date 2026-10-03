<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use DateTimeImmutable;

/**
 * Los avisos de fichaje descartado **con dueño** que la revision diaria puede
 * convertir en incidencia (RN-22, ADR-047), leidos hacia atras de
 * `discarded_scan_reports`.
 *
 * **Solo lectura**, como los demas puertos de la revision: el detector no tiene
 * por donde escribir. Quien guarda los avisos es {@see DiscardedScanReportLog}.
 *
 * Omite los que **ya estaban registrados** al recibirse (`already_recorded`) y
 * los cuyo `scan_id` exista hoy en `scan_events`: un fichaje que si quedo en el
 * registro no tiene nada que revisar.
 */
interface DiscardedScans
{
    /**
     * Avisos atribuidos cuyo `recorded_at` ∈ [from, to], ordenados por
     * `occurred_at` ASC y `scan_id` ASC.
     *
     * @return list<DiscardedScan>
     */
    public function attributedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array;
}
