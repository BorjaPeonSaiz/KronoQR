<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\UseCase\LoginOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `TwoFactorChallenge`: la sesion **pendiente** de
 * segundo factor (RS-06).
 *
 * **El campo se llama `challenge_token` y no `token`, a proposito.** Es lo que
 * impide que un cliente lo guarde donde guarda la sesion: ese token no autoriza
 * ninguna pantalla del panel, y el sintoma de confundirlos —`403` en todas
 * partes— es dificil de relacionar con la causa.
 *
 * **No lleva `user`.** Un reto no ha autenticado a nadie todavia, y devolver el
 * rol y el ambito de una cuenta a quien acaba de acertar una contrasena adelanta
 * datos que puede que nunca llegue a poder ver. El panel los recibe con la sesion,
 * en `/auth/2fa/verify`.
 */
final class TwoFactorChallengeResource extends JsonResource
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
            'challenge_token' => $outcome->token->plainTextToken,
            'token_type' => 'Bearer',
            // Regla dura 3: en UTC con sufijo Z, como todo instante del contrato.
            'expires_at' => $outcome->token->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'enrolment_required' => $outcome->enrolmentRequired,
        ];
    }
}
