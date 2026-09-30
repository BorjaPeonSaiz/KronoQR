<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * El margen de futuro del alta y la correccion manuales llega de la
 * configuracion de la instalacion (`ATTENDANCE_FUTURE_TOLERANCE_MINUTES`, F1) y
 * un negativo no describe ningun margen: rechazaria marcas del pasado, que es
 * justo lo que el panel tiene que poder anotar. Cero si se admite: nada por
 * delante del servidor.
 */
final class InvalidFutureTolerance extends AttendanceDomainException
{
    public static function ofMinutes(int $minutes): self
    {
        return new self(sprintf(
            'The manual entry future tolerance (F1) cannot be negative: %d minutes given.',
            $minutes,
        ));
    }
}
