<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El codigo de empleado generado ya existe.
 *
 * **No llega al cliente**: el caso de uso reintenta con otro codigo. Es una
 * excepcion y no un valor de retorno porque el choque viene del UNIQUE de
 * PostgreSQL, y lo que sube por la pila es un error.
 */
final class EmployeeCodeAlreadyTaken extends WorkforceConflict
{
    public static function forCode(string $code): self
    {
        return new self('Ya existe un empleado con el codigo '.$code.'.');
    }
}
