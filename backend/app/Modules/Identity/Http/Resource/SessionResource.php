<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Session`: el token recien emitido y quien lo tiene.
 *
 * Es el unico sitio del sistema donde un token viaja en claro, y por eso no se
 * registra en ningun log: `token` no aparece en la traza, ni en el log de
 * acceso, ni en un mensaje de error.
 *
 * @property-read array{user: AuthenticatedUser, token: IssuedAccessToken} $resource
 */
final class SessionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{user: AuthenticatedUser, token: IssuedAccessToken} $session */
        $session = $this->resource;

        return [
            'token' => $session['token']->plainTextToken,
            'token_type' => 'Bearer',
            // Regla dura 3: en UTC con sufijo Z, como todo instante del contrato.
            'expires_at' => $session['token']->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'user' => (new ManagementUserResource($session['user']))->toArray($request),
        ];
    }
}
