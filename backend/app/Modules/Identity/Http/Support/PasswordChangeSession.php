<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Support;

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ¿La peticion viene de una sesion abierta con contrasena **temporal**?
 * (RF-ID-10, ADR-051).
 *
 * Esa sesion lleva el unico ambito `password:change`. Lo pregunta el
 * traductor de errores de autorizacion para responder
 * `urn:kronoqr:problem:password-change-required` en lugar del `403` generico,
 * y el middleware de las pocas rutas autenticadas que no exigen ambito.
 *
 * **Por la lista de ambitos y no con `can()`**: `can()` responde que si a un
 * token con `*`, y aqui se pregunta si el token es ESTE tipo de sesion, no si
 * podria hacer algo.
 */
final class PasswordChangeSession
{
    public static function isOpen(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && \is_array($token->abilities)
            && \in_array(TokenAbility::PASSWORD_CHANGE->value, $token->abilities, true);
    }

    public static function response(): JsonResponse
    {
        return ProblemDetails::passwordChangeRequired(ProblemDetails::translated(
            'accounts.password_change_required',
            [],
            'Tu contrasena es temporal. Cambiala para continuar.',
        ));
    }
}
