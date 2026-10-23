<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;

/**
 * **Un intento fallido** de procesar un fichaje, tal y como se apunta en
 * `scan_processing_failures` (RN-22, ADR-061 §1).
 *
 * Sin datos personales (regla dura 21): el `scan_id` lo genera la tablet y no
 * identifica a nadie, `payloadFingerprint` es una huella y no el contenido, y
 * de la excepcion solo viaja **el nombre de su clase**, nunca el mensaje
 * (ADR-048), que puede llevar el SQL con sus valores.
 */
final readonly class ScanFailureAttempt
{
    public function __construct(
        public string $scanId,
        /** `devices.id` del quiosco, del token y nunca del cuerpo. */
        public int $deviceId,
        public ScanOrigin $origin,
        /**
         * Huella del contenido del fichaje: un intento solo suma a la historia
         * si coincide con la del primero (ADR-061 §1). Nula si no hay contenido
         * que huellar.
         */
        public ?string $payloadFingerprint,
        /** Reloj del servidor (`Clock`), en UTC. Nunca el de la tablet. */
        public DateTimeImmutable $failedAt,
        /** `$failure::class`. */
        public string $exceptionClass,
    ) {}
}
