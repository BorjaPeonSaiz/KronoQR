<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use App\Modules\Identity\Infrastructure\Persistence\User;

/**
 * Quien puede cambiar **su propia** contrasena (**RF-ID-10**,
 * `POST /api/v1/auth/password`): cualquier cuenta de gestion, y nadie mas.
 *
 * **Por la clase del dueño del token y no por `isSupportActor()`.** Un acceso
 * de soporte cuelga de `support_grants`, no de `users`: no tiene contrasena
 * que cambiar aqui y recibe `403` explicito con cualquier alcance (ADR-020).
 * Lo mismo el quiosco y la sesion de portal. `User::isSupportActor()` siempre
 * es `false`, asi que preguntarlo no distinguiria nada.
 */
final class OwnPasswordPolicy
{
    public function change(mixed $actor): bool
    {
        return $actor instanceof User && ! $actor->isSupportActor() && $actor->is_active;
    }
}
