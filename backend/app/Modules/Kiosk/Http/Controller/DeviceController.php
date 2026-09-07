<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Application\UseCase\ListDevices;
use App\Modules\Kiosk\Application\UseCase\UnpairDevice;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Http\Request\ListDevicesRequest;
use App\Modules\Kiosk\Http\Request\UnpairDeviceRequest;
use App\Modules\Kiosk\Http\Resource\DeviceListResource;
use App\Modules\Kiosk\Http\Resource\DeviceResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La flota de quioscos vista desde el panel (**RF-PD-06**, RF-PA-07).
 *
 * `GET /api/v1/devices` y `POST /api/v1/devices/{uuid}/unpair`, las dos de
 * `admin` con ambito `settings:*` (§7.3 nota 5).
 *
 * **No hay alta ni edicion manual**, y no es un hueco del contrato: un dispositivo
 * no se da de alta a mano, nace de un emparejamiento. Un `PATCH` que renombrara un
 * quiosco romperia ademas la reactivacion por nombre de ADR-028, que es como se
 * sustituye una tablet averiada.
 *
 * **`unpair` es `POST` y no `DELETE`** porque no borra nada (regla dura 5): es un
 * cambio de estado con consecuencias, como revocar una credencial o dar de baja a
 * un empleado. La fila se queda con su nombre y su historial, porque los fichajes
 * que ese quiosco registro siguen existiendo.
 */
final class DeviceController extends Controller
{
    public function index(ListDevicesRequest $request, ListDevices $devices): JsonResponse
    {
        return (new DeviceListResource($devices->handle()))->response();
    }

    public function unpair(UnpairDeviceRequest $request, UnpairDevice $unpair, string $uuid): JsonResponse
    {
        $device = $unpair->handle($request->toCommand($uuid));

        if (! $device instanceof DeviceSummary) {
            // El `404` del contrato. Llega **despues** de la autorizacion: un rol
            // no autorizado recibe `403` sin llegar a saber si el quiosco existe.
            throw new NotFoundHttpException;
        }

        return (new DeviceResource($device))->response();
    }
}
