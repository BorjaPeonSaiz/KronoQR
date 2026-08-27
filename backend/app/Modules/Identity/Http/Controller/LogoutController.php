<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\LogOutHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/auth/logout` — cierre de sesion.
 *
 * Revoca **el token de esta llamada** y ninguno mas: cerrar sesion en el
 * portatil no puede echar a la misma persona de la tablet donde estaba
 * trabajando.
 *
 * Devuelve `204` sin cuerpo. No hay nada que contar y un cuerpo vacio con
 * estructura solo daria de que hablar al cliente.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogOutHandler $handler): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $handler->handle($token->id);
        }

        return response()->noContent();
    }
}
