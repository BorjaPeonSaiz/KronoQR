<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;

/**
 * La fila de `discarded_scan_reports` que el caso de uso quiere dejar escrita
 * (RN-22, ADR-047).
 *
 * **Sin el contenido del QR ni el codigo de empleado** (RS-03, regla dura 21):
 * solo el resultado de atribuirlos, en {@see DiscardedScanAttribution}.
 */
final readonly class DiscardedScanReportRecord
{
    public function __construct(
        public string $scanId,
        /** `devices.id` del quiosco, del token y nunca del cuerpo. */
        public int $deviceId,
        public ScanOrigin $origin,
        public DateTimeImmutable $occurredAt,
        /** Cuando lo descarto la tablet, con su reloj. Informativo. */
        public DateTimeImmutable $discardedAt,
        /** Recepcion del aviso en servidor (`Clock`). */
        public DateTimeImmutable $recordedAt,
        public int $httpStatus,
        public ?string $problemType,
        public DiscardedScanAttribution $attribution,
    ) {}
}
