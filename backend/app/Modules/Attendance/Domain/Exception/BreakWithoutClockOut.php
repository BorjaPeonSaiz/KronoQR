<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;

/**
 * Un tramo sin salida no puede haber terminado en pausa (RN-05, RF-PA-04; 2.3.0).
 *
 * `ends_in_break` describe como termino un tramo, y un tramo abierto todavia no
 * ha terminado. Es un estado imposible de {@see ShiftTimes},
 * y el borde HTTP lo traduce a `422` en `ends_in_break`.
 */
final class BreakWithoutClockOut extends AttendanceDomainException
{
    public static function create(): self
    {
        return new self('A shift entry without a clock-out cannot end in a break (RN-05).');
    }
}
