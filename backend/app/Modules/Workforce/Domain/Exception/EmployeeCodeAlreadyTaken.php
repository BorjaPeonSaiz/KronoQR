<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El codigo de empleado generado ya existe.
 *
 * **No llega al cliente**: el caso de uso reintenta con otro codigo. Es una
 * excepcion y no un valor de retorno porque el choque viene del UNIQUE de
 * PostgreSQL, y lo que sube por la pila es un error.
 *
 * **Sin el codigo en el mensaje** (ADR-048, regla dura 21). El codigo de
 * empleado es un identificador directo de una persona y la mitad publica de la
 * credencial del portal (ADR-015), y el mensaje de una excepcion acaba en el
 * log tecnico y en `error_events`. Quien la captura ya tiene el codigo en la
 * mano: no necesita que viaje dentro.
 */
final class EmployeeCodeAlreadyTaken extends WorkforceConflict
{
    public static function make(): self
    {
        return new self('Ya existe un empleado con ese codigo.');
    }
}
