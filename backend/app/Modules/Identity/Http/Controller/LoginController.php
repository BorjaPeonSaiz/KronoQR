<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\AuthenticateUserHandler;
use App\Modules\Identity\Http\Request\LoginRequest;
use App\Modules\Identity\Http\Resource\SessionResource;
use App\Modules\Identity\Http\Resource\TwoFactorChallengeResource;
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
 *
 * **Y dos desenlaces de exito** desde la tarea 2.1 (RS-06): `200` con la sesion,
 * o `202` con la sesion **pendiente** de segundo factor. Lo unico que hace este
 * controlador con esa distincion es elegir codigo y recurso; quien decide si hay
 * reto es el caso de uso.
 */
final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUserHandler $handler): JsonResponse
    {
        $outcome = $handler->handle($request->toCommand());

        // DOS CODIGOS Y DOS FORMAS, no un `oneOf` sobre el 200 (RS-06, contrato).
        // Con una sola forma, un cliente que leyera `token` sin mirar nada mas
        // guardaria el token pendiente como si fuera una sesion, y el sintoma
        // —403 en cada pantalla— no se parece en nada a la causa.
        if ($outcome->isPending()) {
            return (new TwoFactorChallengeResource($outcome))
                ->response()
                ->setStatusCode(JsonResponse::HTTP_ACCEPTED);
        }

        return (new SessionResource($outcome))->response()->setStatusCode(JsonResponse::HTTP_OK);
    }
}
