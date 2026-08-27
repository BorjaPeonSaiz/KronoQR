<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * La ventana anti-rebote de RF-AT-06 llega de la configuracion de la
 * instalacion (`ATTENDANCE_DEBOUNCE_SECONDS`) y nadie la revisa antes de
 * aplicarla.
 *
 * Una ventana negativa no significa nada: no es «anti-rebote apagado» —eso es
 * cero, y es legitimo— sino una configuracion mal escrita. Se rechaza al
 * construir la politica y no al evaluarla, para que el fallo aparezca al
 * arrancar con esa configuracion y no en el primer fichaje del turno de noche.
 */
final class InvalidDebounceWindow extends AttendanceDomainException
{
    public static function ofSeconds(int $seconds): self
    {
        return new self(sprintf(
            'The debounce grace window (RF-AT-06) cannot be negative: %d seconds given.',
            $seconds,
        ));
    }
}
