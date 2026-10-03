<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El codigo de empleado no tiene forma de codigo de empleado.
 *
 * **No lleva nunca el valor en el mensaje** (ADR-048, regla dura 21): un codigo
 * de empleado, aunque sea opaco, identifica a una persona dentro de la
 * instalacion y es la mitad de la credencial del portal (ADR-015). El mensaje de
 * una excepcion acaba en el log tecnico y en `error_events`; la longitud basta
 * para diagnosticar.
 */
final class InvalidEmployeeCode extends WorkforceDomainException
{
    public static function empty(): self
    {
        return new self('El codigo de empleado no puede estar vacio.');
    }

    public static function tooLong(int $length, int $max): self
    {
        return new self('El codigo de empleado tiene '.$length.' caracteres y el maximo son '.$max.'.');
    }

    public static function malformed(): self
    {
        return new self('El codigo de empleado solo admite letras mayusculas y digitos.');
    }
}
