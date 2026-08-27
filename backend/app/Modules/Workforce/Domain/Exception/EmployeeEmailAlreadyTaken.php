<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Otro empleado ya tiene ese correo.
 *
 * El correo es opcional (regla dura 12), pero cuando existe es unico: el indice
 * es parcial —`WHERE email IS NOT NULL`— precisamente para que los cientos de
 * empleados sin correo no choquen entre si.
 */
final class EmployeeEmailAlreadyTaken extends WorkforceConflict
{
    public static function make(): self
    {
        // Sin el correo en el mensaje: el texto de una excepcion acaba en un log.
        return new self('Ya existe otro empleado con ese correo.');
    }
}
