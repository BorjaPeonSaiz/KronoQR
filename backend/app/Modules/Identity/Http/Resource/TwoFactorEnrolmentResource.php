<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Domain\ValueObject\TwoFactorEnrolment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `TwoFactorEnrolment`: el secreto TOTP y su URI
 * `otpauth://`.
 *
 * **Es el unico sitio del sistema por el que sale un secreto TOTP**, igual que
 * {@see SessionResource} lo es para un token. Por eso no se registra en ningun
 * log, ni en la traza, ni en el registro de acceso, ni en un mensaje de error: se
 * serializa aqui y se olvida.
 *
 * Envuelve un objeto de valor y **nunca un modelo Eloquent**: asi no hay ninguna
 * via por la que `two_factor_confirmed_at` o la contrasena puedan aparecer en la
 * respuesta al añadir un campo.
 *
 * @property-read TwoFactorEnrolment $resource
 */
final class TwoFactorEnrolmentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TwoFactorEnrolment $enrolment */
        $enrolment = $this->resource;

        return [
            'secret' => $enrolment->secret,
            'otpauth_uri' => $enrolment->otpauthUri,
        ];
    }
}
