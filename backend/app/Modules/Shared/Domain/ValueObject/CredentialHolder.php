<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * **El titular de una tarjeta autentica que ya no vale** (RN-20, ADR-047).
 *
 * Solo existe cuando la firma verifica, la credencial existe y se rechaza
 * porque esta retirada —revocada por la baja de RN-14, por reemision o por
 * perdida— o porque su titular esta de baja. Nunca con una firma invalida, un
 * token desconocido ni una persona suspendida: en esos casos no hay jornada de
 * nadie que revisar (y en el ultimo no hay instante con el que comparar).
 *
 * ## Es un dato interno, nunca una respuesta
 *
 * Viaja del resolver (`Identity`) a la fila de `scan_events` (`Attendance`) y
 * no sube mas: la respuesta del quiosco sigue siendo el rechazo generico y de
 * tiempo constante (RS-03, regla dura 17). Vive en `Shared` por lo mismo que
 * {@see PinClaim}: cruza de un modulo a otro y ninguno puede importar al otro.
 *
 * Lleva el `employee_uuid` y los dos instantes que acotan cuando valia la
 * tarjeta; ni el motivo libre de la revocacion ni nada que identifique (regla
 * dura 21).
 */
final readonly class CredentialHolder
{
    private function __construct(
        /** `employee_uuid` del titular de la tarjeta. */
        public string $employeeUuid,
        /**
         * Cuando se emitio la credencial (`credentials.issued_at`), en UTC. Es la
         * cota inferior: el `occurred_at` lo pone la tablet, y sin ella cualquier
         * fecha anterior —elegida por quien tenga la tarjeta— pediria revision
         * (F2 del dictamen del bloque 18).
         */
        public DateTimeImmutable $issuedAt,
        /**
         * Cuando se retiro la credencial (`credentials.revoked_at`), en UTC.
         * `null` si la credencial sigue vigente y es el titular quien esta de
         * baja: entonces el caso de uso compara con la recepcion del escaneo.
         */
        public ?DateTimeImmutable $withdrawnAt,
    ) {}

    public static function of(string $employeeUuid, DateTimeImmutable $issuedAt, ?DateTimeImmutable $withdrawnAt): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Un titular de credencial necesita su UUID.');
        }

        $utc = new DateTimeZone('UTC');

        return new self($employeeUuid, $issuedAt->setTimezone($utc), $withdrawnAt?->setTimezone($utc));
    }
}
