<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\AuthenticatePortalEmployeeHandler;
use App\Modules\Identity\Http\Request\PortalLoginRequest;
use App\Modules\Identity\Http\Resource\PortalSessionResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/me/login` — acceso del empleado a su portal personal (RF-ID-05,
 * RF-ID-06, RL-05).
 *
 * Publico y con `throttle:portal` (10 r/m, §7.1). El bloqueo creciente por
 * intentos fallidos es **otro control** y vive donde vive el de todos los PIN
 * del producto: el limitador cuenta peticiones por origen, el bloqueo cuenta
 * fallos por empleado y por puerta (RS-12, §7.5). Hacen falta los dos y ninguno
 * ve lo que ve el otro.
 *
 * **Este controlador no tiene ni un `if`.** Los cinco desenlaces de rechazo son
 * la misma excepcion, que el renderizador global convierte en el mismo `401`
 * (regla dura 17). Una rama aqui seria el sitio exacto donde alguien acabaria
 * añadiendo «pero si esta bloqueado, dile cuanto falta».
 *
 * **Y no mide nada.** El span y el log del acceso viven dentro del caso de uso,
 * donde se conoce el desenlace real; desde aqui solo se veria «hubo excepcion».
 */
final class PortalLoginController extends Controller
{
    public function __invoke(
        PortalLoginRequest $request,
        AuthenticatePortalEmployeeHandler $handler,
    ): JsonResponse {
        $session = $handler->handle($request->toCommand());

        return (new PortalSessionResource($session))->response()->setStatusCode(JsonResponse::HTTP_OK);
    }
}
