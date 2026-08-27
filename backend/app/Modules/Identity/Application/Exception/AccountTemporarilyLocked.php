<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * La cuenta esta bloqueada por intentos fallidos (RF-ID-01).
 *
 * Se distingue de {@see AuthenticationFailed} porque el cliente necesita saber
 * que esperar es la accion correcta: reintentar antes no va a funcionar aunque
 * la contrasena sea buena. Lleva los segundos que faltan, que es lo que viaja en
 * `Retry-After`.
 *
 * Que exista esta respuesta no filtra si la cuenta existe: el bloqueo se aplica
 * igual a un correo que no esta dado de alta, porque el contador se lleva por
 * clave y no por cuenta encontrada.
 */
final class AccountTemporarilyLocked extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Acceso bloqueado temporalmente por intentos fallidos.');
    }
}
