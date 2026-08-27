<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `ManagementUser` del contrato.
 *
 * Envuelve un objeto de valor y **nunca un modelo Eloquent**: asi no hay ninguna
 * via por la que `password` o `remember_token` puedan aparecer en una respuesta
 * por descuido al añadir un campo.
 *
 * @property-read AuthenticatedUser $resource
 */
final class ManagementUserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuthenticatedUser $user */
        $user = $this->resource;

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'roles' => $user->roleNames(),
            // El ambito viaja para que el panel no ofrezca lo que despues seria
            // un 403. No es autorizacion: la de verdad esta en el servidor
            // (regla dura 18).
            'abilities' => $user->abilityNames(),
        ];
    }
}
