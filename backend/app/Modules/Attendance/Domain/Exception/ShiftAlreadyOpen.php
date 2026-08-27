<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * RN-01: un empleado no puede tener mas de un turno abierto simultaneamente.
 *
 * Invariante de dominio **y** restriccion en base de datos: el indice unico
 * parcial `one_open_shift_per_employee` es la ultima linea de defensa si la
 * aplicacion tiene un fallo de concurrencia. Que exista en los dos sitios no es
 * duplicacion, es defensa en profundidad (ADR-003, ADR-026).
 */
final class ShiftAlreadyOpen extends AttendanceDomainException
{
    public static function forEmployee(string $employeeUuid): self
    {
        return new self('Employee '.$employeeUuid.' already has an open shift entry (RN-01).');
    }
}
