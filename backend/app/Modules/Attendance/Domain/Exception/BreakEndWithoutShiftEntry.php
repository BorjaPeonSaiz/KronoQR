<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Se intento resolver un escaneo como vuelta de pausa sin decir de que tramo
 * vuelve.
 *
 * No es un error de datos del empleado sino de programacion: por ADR-024 la
 * jornada que un `break_end` continua es la del tramo que la pausa cerro
 * (RN-05), y sin ese tramo la unica forma de encontrarla seria la fecha civil
 * del escaneo, que es justo lo que parte el turno de noche. La politica nunca
 * lo produce —cuando no hay pausa que continuar resuelve `CLOCK_IN`—, asi que
 * llegar aqui significa que alguien construyo la resolucion a mano.
 *
 * Se lanza en lugar de degradar a `CLOCK_IN` en silencio: una degradacion
 * silenciosa aqui reparte las horas de una jornada entre dos dias y nadie se
 * entera hasta la nomina.
 */
final class BreakEndWithoutShiftEntry extends AttendanceDomainException
{
    public static function create(): self
    {
        return new self('A break end must name the shift entry whose work day it continues (ADR-024, RN-05).');
    }
}
