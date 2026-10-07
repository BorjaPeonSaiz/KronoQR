<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Quien actua sobre otra cuenta no ha confirmado que es el (RF-ID-10, ASVS
 * V3.7.1): su codigo del autenticador, o su contrasena si no tiene segundo
 * factor, falta o no vale. `422` con el error en el campo que lo pedia.
 *
 * `422` y no `401`: la sesion sigue siendo valida, y devolver al panel al acceso
 * por un codigo mal tecleado seria castigar al administrador legitimo.
 */
final class ActorReauthenticationFailed extends RuntimeException
{
    public const string TRANSLATION_KEY = 'accounts.reauthentication_failed';

    public function __construct(public readonly string $field)
    {
        parent::__construct('La confirmacion de identidad de quien actua no es correcta.');
    }
}
