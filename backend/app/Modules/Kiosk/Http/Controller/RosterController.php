<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Application\UseCase\BuildKioskRoster;
use App\Modules\Kiosk\Http\Request\KioskRosterRequest;
use App\Modules\Kiosk\Http\Resource\KioskRosterResource;
use App\Modules\Kiosk\Http\Support\KioskDevice;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/kiosk/roster` — el padron minimo cacheable (RF-KI-03).
 *
 * Delgado: resuelve el dispositivo del token, invoca el caso de uso y devuelve el
 * `Resource`. **El centro no se lee de la peticion**, se deduce del token (ver
 * {@see KioskDevice}): es lo que impide que un quiosco pida el padron de otro
 * hotel de la cadena.
 *
 * La auditoria de la divulgacion (RS-05) la escribe el caso de uso, dentro, y no
 * este controlador: si dependiera de una linea aqui, el dia que exista un segundo
 * camino hacia el padron —una exportacion, un diagnostico— habria que acordarse
 * de repetirla.
 */
final class RosterController extends Controller
{
    public function __invoke(KioskRosterRequest $request, BuildKioskRoster $roster): JsonResponse
    {
        $device = KioskDevice::of($request);

        return (new KioskRosterResource($roster->forSite($device->siteId, $device->uuid)))->response();
    }
}
