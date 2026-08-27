<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El codigo de empleado no tiene forma de codigo de empleado.
 *
 * No lleva nunca datos de la persona en el mensaje: el codigo si, porque es
 * opaco por construccion y no identifica a nadie por si solo.
 */
final class InvalidEmployeeCode extends WorkforceDomainException
{
    public static function empty(): self
    {
        return new self('El codigo de empleado no puede estar vacio.');
    }

    public static function tooLong(string $value, int $max): self
    {
        return new self('El codigo de empleado «'.$value.'» supera los '.$max.' caracteres.');
    }

    public static function malformed(string $value): self
    {
        return new self('El codigo de empleado «'.$value.'» solo admite letras mayusculas y digitos.');
    }
}
