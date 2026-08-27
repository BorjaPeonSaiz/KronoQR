<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Una duracion trabajada negativa no es un dato raro: es un dato imposible.
 *
 * Se rechaza al construir el objeto de valor y no se comprueba despues, que es
 * la diferencia entre un tipo que impide el error y una validacion que alguien
 * tiene que acordarse de escribir en cada sitio donde se sume.
 */
final class NegativeWorkedDuration extends AttendanceDomainException
{
    public static function ofMinutes(int $minutes): self
    {
        return new self('Worked duration cannot be negative, got '.$minutes.' minutes.');
    }
}
