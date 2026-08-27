<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * La zona horaria del centro no existe en la base de datos de husos.
 *
 * Es un error de dominio y no de validacion de formulario porque de este dato
 * depende a que jornada se atribuye cada tramo (RN-05): admitirlo «por ahora»
 * seria admitir un calculo horario equivocado.
 */
final class UnknownTimezone extends WorkforceDomainException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self('«'.$identifier.'» no es un identificador IANA de zona horaria.');
    }
}
