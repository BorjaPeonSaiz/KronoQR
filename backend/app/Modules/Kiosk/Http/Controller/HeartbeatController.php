<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Application\UseCase\RecordHeartbeat;
use App\Modules\Kiosk\Http\Request\KioskHeartbeatRequest;
use App\Modules\Kiosk\Http\Resource\KioskHeartbeatResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/kiosk/heartbeat` — latido y telemetria del quiosco (RF-PA-07).
 *
 * Delgado: valida, construye el comando, invoca el caso de uso y devuelve el
 * `Resource`.
 *
 * **Nunca falla por culpa de la telemetria.** Si Redis no responde, el caso de uso
 * escribe igualmente `devices.last_seen_at` y la respuesta sale: un `500` aqui
 * dejaria al quiosco reintentando el latido justo cuando la instalacion ya tiene
 * un problema, y ademas apagaria la unica senal que dice que la tablet sigue viva.
 */
final class HeartbeatController extends Controller
{
    public function __invoke(KioskHeartbeatRequest $request, RecordHeartbeat $heartbeat): JsonResponse
    {
        return (new KioskHeartbeatResource($heartbeat->handle($request->toCommand())))->response();
    }
}
