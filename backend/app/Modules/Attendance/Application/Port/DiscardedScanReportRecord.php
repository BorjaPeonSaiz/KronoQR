<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La fila de `discarded_scan_reports` que el caso de uso quiere dejar escrita
 * (RN-22, ADR-047).
 *
 * **Sin el contenido del QR ni el codigo de empleado** (RS-03, regla dura 21):
 * solo el resultado de atribuirlos, en {@see DiscardedScanAttribution}.
 *
 * **Dos origenes desde la 2.3.0** (ADR-061): el aviso del quiosco, sin intentos,
 * y el descarte del propio servidor ({@see self::SOURCE_SERVER}), con cuantos
 * intentos fallaron y cuando fallo el primero. El constructor exige la misma
 * coherencia que `discarded_scan_reports_chk_source`: un error asi es del
 * programa y no debe llegar a la base de datos para descubrirse.
 */
final readonly class DiscardedScanReportRecord
{
    public const string SOURCE_KIOSK = 'kiosk';

    public const string SOURCE_SERVER = 'server';

    public function __construct(
        public string $scanId,
        /** `devices.id` del quiosco, del token y nunca del cuerpo. */
        public int $deviceId,
        public ScanOrigin $origin,
        public DateTimeImmutable $occurredAt,
        /** Cuando lo descarto la tablet, con su reloj; en un descarte del servidor, su recepcion. */
        public DateTimeImmutable $discardedAt,
        /** Recepcion del aviso en servidor (`Clock`). */
        public DateTimeImmutable $recordedAt,
        public int $httpStatus,
        public ?string $problemType,
        public DiscardedScanAttribution $attribution,
        /** `kiosk` (aviso de la tablet) o `server` (ADR-061). */
        public string $source = self::SOURCE_KIOSK,
        /** Solo `server`: intentos fallidos contados, el ultimo incluido. */
        public ?int $failedAttempts = null,
        /** Solo `server`: reloj del servidor en el primer intento contado. */
        public ?DateTimeImmutable $firstFailedAt = null,
    ) {
        $fromServer = $source === self::SOURCE_SERVER;

        if (! $fromServer && $source !== self::SOURCE_KIOSK) {
            throw new InvalidArgumentException('Un descarte lo avisa el quiosco o lo decide el servidor, y ha llegado '.$source.'.');
        }

        if ($fromServer !== ($failedAttempts !== null) || $fromServer !== ($firstFailedAt instanceof DateTimeImmutable)) {
            throw new InvalidArgumentException('Solo el descarte del servidor lleva sus intentos y su primer fallo (ADR-061).');
        }
    }
}
