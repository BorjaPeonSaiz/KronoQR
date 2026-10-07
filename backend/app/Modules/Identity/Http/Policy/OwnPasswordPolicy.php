<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use App\Modules\Identity\Infrastructure\Persistence\User;

/**
 * Quien puede cambiar **su propia** contrasena (**RF-ID-10**,
 * `POST /api/v1/auth/password`): una cuenta de gestion activa, y nadie mas.
 *
 * **Por la clase del dueño del token.** Un acceso de soporte cuelga de
 * `support_grants`, no de `users`: no tiene contrasena que cambiar aqui y
 * recibe `403` explicito con cualquier alcance (ADR-020). Lo mismo el quiosco y
 * la sesion de portal. Preguntar `isSupportActor()` no añadiria nada: para una
 * fila de `users` siempre es `false`.
 *
 * Se llama por su nombre desde `ChangeOwnPasswordRequest` y no por el `Gate`: a
 * esta ruta llegan `tokenable` que no son `Authorizable` y que el `Gate::before`
 * del paquete de permisos no admite.
 */
final class OwnPasswordPolicy
{
    public function change(mixed $actor): bool
    {
        return $actor instanceof User && $actor->is_active;
    }
}
