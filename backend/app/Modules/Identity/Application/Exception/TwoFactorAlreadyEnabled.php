<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Se intenta dar de alta un segundo factor en una cuenta que ya lo tiene activo
 * (RS-06).
 *
 * **`409` y no `422`**: no hay ningun campo que corregir, lo que pasa es que el
 * recurso ya esta en otro estado. Y sobre todo **no se sustituye en silencio**:
 * si `/auth/2fa/enrol` reemplazara un TOTP activo, cualquiera con la contrasena
 * de alguien podria dejarle fuera de su propia cuenta y quedarse el segundo
 * factor. Reemplazarlo es un acto de administracion —`identity:2fa-reset`, con
 * asiento en `audit_log`— y no algo que se haga con una sesion pendiente.
 */
final class TwoFactorAlreadyEnabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta cuenta ya tiene un segundo factor activo. Para cambiarlo hay que retirarlo antes.');
    }
}
