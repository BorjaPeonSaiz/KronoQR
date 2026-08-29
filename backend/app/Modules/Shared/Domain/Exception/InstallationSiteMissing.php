<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use RuntimeException;

/**
 * La instalacion no tiene todavia su centro de trabajo (ADR-040).
 *
 * Solo puede ocurrir antes de la puesta en marcha (RF-PD-03): sin centro no hay
 * zona horaria, y sin zona horaria RN-05 no es expresable, asi que ningun alta
 * ni ninguna lectura que dependa del centro puede completarse. Se responde con
 * `409`: no es un error del cliente ni del servidor, es un estado de la
 * instalacion que alguien tiene que resolver una sola vez.
 */
final class InstallationSiteMissing extends RuntimeException
{
    public static function make(): self
    {
        return new self('La instalacion no tiene centro de trabajo: completa la puesta en marcha antes de continuar.');
    }
}
