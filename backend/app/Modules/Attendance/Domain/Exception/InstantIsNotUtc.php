<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use DateTimeImmutable;

/**
 * RN-04, regla dura 3: todo instante del registro se maneja en UTC.
 *
 * La conversion a la zona del centro ocurre en presentacion y, para resolver la
 * jornada, dentro de `WorkDate`. Un instante que entra al agregado con la zona
 * local puesta casi siempre significa que alguien se salto la conversion, y el
 * sintoma aparece meses despues en un informe con una hora de diferencia.
 */
final class InstantIsNotUtc extends AttendanceDomainException
{
    public static function forField(string $field, DateTimeImmutable $instant): self
    {
        return new self(sprintf(
            'The instant given for "%s" is not UTC: timezone "%s", offset %+d seconds.',
            $field,
            $instant->getTimezone()->getName(),
            $instant->getOffset(),
        ));
    }
}
