<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use DateTimeImmutable;

/**
 * RN-02: los tramos vigentes de un mismo empleado no pueden solaparse.
 *
 * El agregado razona con intervalos semiabiertos `[inicio, fin)`, los mismos
 * que la restriccion `shift_entries_no_overlap` de PostgreSQL, que usa
 * `tstzrange(clocked_in_at, clocked_out_at)` con su forma por defecto. Si el
 * dominio y la base de datos discrepasen en el borde, un fichaje que sale a las
 * 14:00 y otro que entra a las 14:00 pasaria por uno y lo rechazaria el otro.
 */
final class OverlappingShiftEntry extends AttendanceDomainException
{
    public static function at(string $employeeUuid, DateTimeImmutable $instant): self
    {
        return new self(sprintf(
            'A shift entry of employee %s already covers %s (RN-02).',
            $employeeUuid,
            $instant->format(DateTimeImmutable::ATOM),
        ));
    }
}
