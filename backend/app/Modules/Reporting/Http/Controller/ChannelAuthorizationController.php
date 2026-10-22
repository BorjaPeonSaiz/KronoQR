<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Http\Request\AuthorizeChannelRequest;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasting;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/broadcasting/auth`: la firma de la suscripcion a un canal de
 * presencia (ADR-011, RF-PA-01).
 *
 * Sustituye a la ruta que registraba `withBroadcasting()`, que aceptaba `GET` y
 * `POST`, no validaba la peticion y respondia JSONP con un `callback` (B3 y
 * R6-BD-02 de la verificacion de la 2.2.0). Quien decide si se firma sigue siendo
 * el difusor: ejecuta el callback del canal de `routes/channels.php` y responde
 * `403` si dice que no.
 */
final class ChannelAuthorizationController extends Controller
{
    public function __invoke(AuthorizeChannelRequest $request, Broadcasting $broadcasting): JsonResponse
    {
        // El difusor ACTIVO en esta peticion, no uno resuelto al arrancar: es el
        // que tiene registrados los canales de `routes/channels.php`.
        $signature = $broadcasting->connection()->auth($request);

        return $signature instanceof JsonResponse ? $signature : new JsonResponse($signature);
    }
}
