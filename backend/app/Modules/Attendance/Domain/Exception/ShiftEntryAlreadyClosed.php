<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Un tramo cerrado no se vuelve a cerrar, y tampoco se reabre.
 *
 * Rectificar un tramo ya cerrado es una **correccion**, no una segunda salida:
 * crea una version nueva y conserva la anterior como `superseded`, con autor,
 * momento y motivo (RN-13, RL-04, ADR-026). Eso llega en la tarea 1.15 y pasa
 * por su propio caso de uso, nunca por este metodo.
 */
final class ShiftEntryAlreadyClosed extends AttendanceDomainException
{
    public static function withUuid(string $shiftEntryUuid): self
    {
        return new self('Shift entry '.$shiftEntryUuid.' is already closed; rectifying it is a correction (RN-13).');
    }
}
