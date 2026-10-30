<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * **La persona de baja que tecleo su PIN correcto** en el quiosco (RN-20,
 * enmienda del 29-10-2026 de ADR-043; ADR-062 §8).
 *
 * Solo existe cuando el codigo es de una persona **de baja** y el PIN que
 * tecleo **es el suyo**: el verificador lo comparo contra su hash real (la baja
 * se evalua despues de la comparacion) y el bloqueo no estaba activo. Nunca con
 * un PIN incorrecto, un bloqueo, una persona suspendida ni desde el portal.
 *
 * ## No es un `PinClaim`, y tampoco una autenticacion
 *
 * `PinClaim` dice «a quien correspondia un codigo que no verifico»; este, «quien
 * era, porque el PIN acerto». El PIN si identifica, asi que el fichaje puede
 * atribuir la fila (`scan_events.employee_id`) y marcarla para revision, como la
 * tarjeta autentica retirada de {@see CredentialHolder}. **Pero no autentica**:
 * el rechazo hacia fuera es el mismo, `employeeUuid()` sigue nulo en
 * {@see PinVerification} y en {@see CredentialResolution}, ni se borra el
 * contador de intentos ni hay *rehash*.
 *
 * ## Lleva lo justo para decidir sin E/S
 *
 * Las fechas del periodo que cerro la baja (la proyeccion de la ficha:
 * `employees.hired_at` y `terminated_at`) y la zona del centro salen de **la
 * misma fila que el verificador ya lee** para todos los codigos. Asi el caso de
 * uso decide si la fecha civil del `occurred_at` cae en el periodo sin una
 * consulta que solo pagaria el PIN correcto de una persona de baja, que seria un
 * oraculo de tiempo para adivinarlo (RS-03, regla dura 17).
 *
 * Ni el codigo de empleado ni el PIN (regla dura 21).
 */
final readonly class OffboardedPinHolder
{
    private function __construct(
        /** `employee_uuid` de la persona de baja. */
        public string $employeeUuid,
        /** Fecha de alta del periodo que cerro la baja (`Y-m-d`). */
        public string $hiredOn,
        /** Fecha de cese de ese periodo (`Y-m-d`). */
        public string $terminatedOn,
        /** Zona del centro, en la que se lee la fecha civil del `occurred_at`. */
        public DateTimeZone $timezone,
    ) {}

    public static function of(string $employeeUuid, string $hiredOn, string $terminatedOn, DateTimeZone $timezone): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una persona de baja identificada por su PIN necesita su UUID.');
        }

        self::assertCivilDate($hiredOn);
        self::assertCivilDate($terminatedOn);

        if ($terminatedOn < $hiredOn) {
            throw new InvalidArgumentException('La fecha de cese no puede ser anterior a la de alta.');
        }

        return new self($employeeUuid, $hiredOn, $terminatedOn, $timezone);
    }

    private static function assertCivilDate(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Las fechas del periodo tienen que ser fechas civiles Y-m-d validas.');
        }
    }
}
