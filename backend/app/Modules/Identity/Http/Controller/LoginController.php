<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\AuthenticateUserHandler;
use App\Modules\Identity\Http\Request\LoginRequest;
use App\Modules\Identity\Http\Resource\SessionResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/login` — acceso al panel de gestion (RF-ID-01).
 *
 * Publico y con `throttle:auth` (5 r/m, §7.1). El bloqueo por intentos fallidos
 * es otro control y vive en el caso de uso: el limitador cuenta peticiones por
 * origen, el bloqueo cuenta fallos por cuenta.
 *
 * El controlador no decide nada: valida con el `FormRequest`, invoca el caso de
 * uso y serializa. Los dos desenlaces de error —credenciales no validas y
 * bloqueo— son excepciones que el renderizador global convierte en
 * `application/problem+json`, para que un fallo no dependa de que cada
 * controlador se acuerde de darle forma.
 */
final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUserHandler $handler): JsonResponse
    {
        $session = $handler->handle($request->toCommand());

        return (new SessionResource($session))->response()->setStatusCode(JsonResponse::HTTP_OK);
    }
}
