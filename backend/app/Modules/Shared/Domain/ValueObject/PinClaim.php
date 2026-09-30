<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * **A quien correspondia el codigo** de un fichaje por PIN que no verifico
 * (RN-19, ADR-043).
 *
 * Solo existe cuando el codigo tecleado es de una persona que **puede fichar**
 * (`EmploymentStatus::canClock()`) y el PIN no verifico —erroneo, no emitido o
 * bloqueo activo—. Nunca con un codigo inexistente, una baja, un suspendido ni
 * un sobre que no abre: en esos casos no hay jornada de nadie que revisar.
 *
 * ## Es un dato interno, nunca una respuesta
 *
 * Viaja del verificador (`Workforce`) a la fila de `scan_events` (`Attendance`)
 * y **no sube mas**: ni al evento `ScanRejected`, ni al resultado del caso de
 * uso, ni a la API, ni al log tecnico. La respuesta de `/scan/pin` sigue siendo
 * una sola para los cinco rechazos (regla dura 17, RS-03). Vive en `Shared`
 * por lo mismo que {@see PinVerification}: cruza de un modulo a otro y ninguno
 * puede importar al otro (doc 02 §1.6).
 *
 * Lleva el `employee_uuid` y nada mas que identifique: ni el codigo de
 * empleado ni el PIN (regla dura 21).
 */
final readonly class PinClaim
{
    private function __construct(
        /** `employee_uuid` del dueño del codigo tecleado. */
        public string $claimantUuid,
        /** El intento abrio o encontro el bloqueo por intentos de RS-12. */
        public bool $lockout,
    ) {}

    public static function of(string $claimantUuid, bool $lockout): self
    {
        if ($claimantUuid === '') {
            throw new InvalidArgumentException('Un PinClaim necesita el UUID del dueño del codigo.');
        }

        return new self($claimantUuid, $lockout);
    }
}
