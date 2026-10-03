<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Un aviso de fichaje descartado tal como llega del quiosco (esquema
 * `DiscardedScanReport`, RN-22, ADR-047).
 *
 * Lleva el `qr_payload` leido **o** el `employee_code` tecleado, solo para
 * atribuir el aviso: el caso de uso los usa y los tira, **nunca se guardan**
 * (RS-03, regla dura 21). Nunca el PIN ni su sobre.
 */
final readonly class DiscardedScanReportInput
{
    public function __construct(
        public string $scanId,
        public DateTimeImmutable $occurredAt,
        /** `qr_kiosk` o `pin_kiosk`, segun el `kind` del aviso. */
        public ScanOrigin $origin,
        public int $httpStatus,
        public ?string $problemType,
        /** Reloj de la tablet. Informativo. */
        public DateTimeImmutable $discardedAt,
        #[SensitiveParameter]
        public ?string $qrPayload = null,
        public ?string $employeeCode = null,
    ) {}
}
