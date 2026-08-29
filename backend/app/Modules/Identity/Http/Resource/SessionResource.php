<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\UseCase\LoginOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Session`: el token recien emitido y quien lo tiene.
 *
 * Es el unico sitio del sistema donde un token de sesion viaja en claro, y por eso
 * no se registra en ningun log: `token` no aparece en la traza, ni en el log de
 * acceso, ni en un mensaje de error.
 *
 * **Solo serializa desenlaces completos.** Recibe un {@see LoginOutcome} y no un
 * par suelto de usuario y token: si le llegara un reto pendiente, publicaria como
 * `token` un valor que no autoriza nada. La sesion pendiente tiene su propio
 * recurso, con otro nombre de campo, precisamente para que esa confusion no quepa.
 *
 * @property-read LoginOutcome $resource
 */
final class SessionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoginOutcome $outcome */
        $outcome = $this->resource;

        return [
            'token' => $outcome->token->plainTextToken,
            'token_type' => 'Bearer',
            // Regla dura 3: en UTC con sufijo Z, como todo instante del contrato.
            'expires_at' => $outcome->token->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'user' => (new ManagementUserResource($outcome->user))->toArray($request),
        ];
    }
}
