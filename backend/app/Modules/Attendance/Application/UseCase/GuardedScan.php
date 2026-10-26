<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterPinScanCommand;
use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Domain\Support\PayloadFingerprint;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Lo que {@see UnprocessableScanGuard} necesita saber de un fichaje para
 * contar sus fallos y, si llega el caso, descartarlo (RN-22, ADR-061).
 *
 * Lo construyen las dos puertas —la tarjeta y el PIN— con sus dos fabricas, y
 * por eso el guardian no sabe por cual entro.
 *
 * - **`contentFingerprint`**: la huella del contenido con la que se cuentan los
 *   intentos. Un intento solo suma si coincide con la del primero (ADR-061 §1).
 *   Por tarjeta, el SHA-256 del payload —la misma huella que
 *   `scan_events.payload_fingerprint`—; por PIN, el del codigo y el sobre
 *   sellado juntos, que la tablet no cambia entre reenvios del mismo fichaje.
 *   **Nunca el contenido**: lo que se guarda es la huella.
 * - **`qrPayload`** y **`employeeCode`**: solo para atribuir el descarte, con la
 *   misma politica que el aviso del quiosco. No se guardan (regla dura 21).
 */
final readonly class GuardedScan
{
    private function __construct(
        public string $scanId,
        public int $deviceId,
        public string $deviceUuid,
        public ScanOrigin $origin,
        public ScanIntent $intent,
        public DateTimeImmutable $occurredAt,
        public string $contentFingerprint,
        #[SensitiveParameter] public ?string $qrPayload,
        #[SensitiveParameter] public ?string $employeeCode,
    ) {}

    public static function ofScan(RegisterScanCommand $command): self
    {
        return new self(
            scanId: $command->scanId,
            deviceId: $command->deviceId,
            deviceUuid: $command->deviceUuid,
            origin: $command->origin,
            intent: $command->intent,
            occurredAt: $command->occurredAt,
            contentFingerprint: PayloadFingerprint::ofQrPayload($command->qrPayload ?? ''),
            qrPayload: $command->qrPayload,
            employeeCode: null,
        );
    }

    public static function ofPinScan(RegisterPinScanCommand $command): self
    {
        return new self(
            scanId: $command->scanId,
            deviceId: $command->deviceId,
            deviceUuid: $command->deviceUuid,
            origin: ScanOrigin::PIN_KIOSK,
            intent: $command->intent,
            occurredAt: $command->occurredAt,
            // El separador impide que dos pares distintos den la misma cadena.
            contentFingerprint: hash('sha256', 'pin|'.$command->employeeCode.'|'.$command->sealedPin),
            qrPayload: null,
            employeeCode: $command->employeeCode,
        );
    }

    /**
     * La huella que va a `scan_events.payload_fingerprint`: la del QR, como en
     * cualquier otro escaneo de tarjeta; nula por PIN, como en cualquier otro
     * fichaje por PIN (no hay tarjeta de la que tomarla).
     */
    public function scanEventFingerprint(): ?string
    {
        return $this->origin === ScanOrigin::PIN_KIOSK ? null : $this->contentFingerprint;
    }
}
