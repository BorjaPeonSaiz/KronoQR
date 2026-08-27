<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Port\PinDeliveryRecord;
use App\Modules\Workforce\Application\UseCase\IssuedPin;
use App\Modules\Workforce\Application\UseCase\RecordPinDeliveryHandler;
use App\Modules\Workforce\Application\UseCase\ResetEmployeePinHandler;
use App\Modules\Workforce\Http\Request\RecordPinDeliveryRequest;
use App\Modules\Workforce\Http\Request\ResetEmployeePinRequest;
use App\Modules\Workforce\Http\Resource\IssuedPinResource;
use App\Modules\Workforce\Http\Resource\PinDeliveryResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PIN del empleado: restablecerlo y registrar su entrega (RF-ID-09).
 *
 * El controlador no decide nada: autoriza y valida con el `FormRequest`, invoca
 * el caso de uso y serializa con el `Resource` (doc 02 §3.5).
 *
 * **Dos endpoints y no uno con una bandera.** Restablecer genera una credencial
 * nueva; entregar afirma que llego a su destinatario. Son dos hechos con dos
 * asientos distintos en `audit_log` —`pin.reset` y `pin.delivered`— y quien
 * revise el trail tiene que poder distinguirlos.
 *
 * **`404` para lo que no existe y para lo que no alcanza** (regla dura 17). Un
 * `uuid` desconocido responde igual que uno fuera del alcance de quien pregunta:
 * si no, este endpoint diria que empleados existen a quien pudiera llamarlo.
 */
final class EmployeePinController extends Controller
{
    /**
     * `POST /api/v1/employees/{uuid}/pin/reset`.
     *
     * La respuesta lleva el PIN en claro, y es **la unica del producto que lo
     * hace**. Quien la recibe lo anota o lo entrega en ese momento: no hay
     * segunda oportunidad, y esa es la razon de que el hash sea la unica copia.
     */
    public function reset(
        ResetEmployeePinRequest $request,
        string $uuid,
        ResetEmployeePinHandler $handler,
    ): JsonResponse {
        $issued = $handler->handle($request->toCommand($uuid));

        if (! $issued instanceof IssuedPin) {
            throw new NotFoundHttpException;
        }

        return (new IssuedPinResource($issued))->response();
    }

    /**
     * `POST /api/v1/employees/{uuid}/pin/deliver`.
     *
     * Sin PIN en la respuesta: el acuse dice quien entrego, a quien y cuando.
     */
    public function deliver(
        RecordPinDeliveryRequest $request,
        string $uuid,
        RecordPinDeliveryHandler $handler,
    ): JsonResponse {
        $delivery = $handler->handle($request->toCommand($uuid));

        if (! $delivery instanceof PinDeliveryRecord) {
            throw new NotFoundHttpException;
        }

        return (new PinDeliveryResource($delivery))->response();
    }
}
