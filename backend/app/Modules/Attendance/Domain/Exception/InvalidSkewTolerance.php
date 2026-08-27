<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * La tolerancia de desfase de reloj llega de la configuracion de la instalacion
 * (`ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`, RF-AT-10) y nadie la revisa antes de
 * aplicarla.
 *
 * Una tolerancia negativa no significa nada. **Cero tampoco es «apagado» aqui**,
 * al contrario que en el anti-rebote: con cero, cualquier escaneo que no llegue
 * en el mismo segundo en que ocurrio pide validacion, que es una instalacion
 * pidiendo revisar todos sus fichajes. Es una configuracion extrema pero
 * coherente, asi que se admite; lo que no se admite es un numero que no describe
 * ninguna duracion.
 *
 * Se rechaza al construir la politica y no al evaluarla, para que el fallo
 * aparezca al arrancar con esa configuracion y no en el primer fichaje del
 * turno de noche.
 */
final class InvalidSkewTolerance extends AttendanceDomainException
{
    public static function ofMinutes(int $minutes): self
    {
        return new self(sprintf(
            'The clock skew tolerance (RF-AT-10, RN-15) cannot be negative: %d minutes given.',
            $minutes,
        ));
    }
}
