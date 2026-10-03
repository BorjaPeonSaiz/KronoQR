<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use InvalidArgumentException;

/**
 * Los avisos de fichajes descartados de un quiosco (`POST /api/v1/scan/discarded`,
 * RN-22, ADR-047).
 *
 * El dispositivo sale del token, nunca del cuerpo. Diez avisos como mucho: cada
 * uno paga el suelo de tiempo constante de la atribucion (RS-03).
 */
final readonly class ReportDiscardedScansCommand
{
    public const int MAX_REPORTS = 10;

    /**
     * @param  list<DiscardedScanReportInput>  $reports
     */
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        public array $reports,
    ) {
        if ($reports === [] || \count($reports) > self::MAX_REPORTS) {
            throw new InvalidArgumentException('Un envio de avisos lleva entre 1 y '.self::MAX_REPORTS.' avisos.');
        }
    }
}
