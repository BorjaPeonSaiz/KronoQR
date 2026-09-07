<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Application\Exception\PairingUnavailable;
use App\Modules\Kiosk\Application\UseCase\ClaimPairing;
use App\Modules\Kiosk\Application\UseCase\ConfirmPairing;
use App\Modules\Kiosk\Application\UseCase\RequestPairing;
use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Kiosk\Http\Request\ClaimPairingRequest;
use App\Modules\Kiosk\Http\Request\ConfirmPairingRequest;
use App\Modules\Kiosk\Http\Request\RequestPairingRequest;
use App\Modules\Kiosk\Http\Resource\PairingClaimResource;
use App\Modules\Kiosk\Http\Resource\PairingConfirmedResource;
use App\Modules\Kiosk\Http\Resource\PairingRequestedResource;
use App\Modules\Kiosk\Http\Response\PairingCodeRejectedResponse;
use App\Modules\Kiosk\Http\Response\PairingRejectedResponse;
use Illuminate\Http\JsonResponse;

/**
 * Los tres pasos del emparejamiento de una tablet (**RF-PD-06**, tarea 5.6).
 *
 * `POST /api/v1/kiosk/pair` (publica) → `POST /api/v1/kiosk/pair/confirm`
 * (`admin`) → `POST /api/v1/kiosk/pair/claim` (publica).
 *
 * Delgado como el resto: valida, construye el comando, invoca el caso de uso y
 * serializa. **Ninguna decision vive aqui.** Si un codigo sirve lo decide el
 * agregado, quien gana una carrera lo decide PostgreSQL, y el asiento de
 * `audit_log` lo escribe el listener de `Compliance`.
 *
 * ## Lo unico que este controlador si decide: que forma tiene un «no»
 *
 * Y son tres formas distintas a proposito:
 *
 * - `claim` rechazado → `PairingRejected`, generico y **de tiempo constante**
 *   entre sus tres causas (regla dura 17, RS-03). El suelo de tiempo lo paga el
 *   caso de uso; aqui solo se serializa.
 * - `confirm` con un codigo que no sirve → `PairingCodeRejected`, tambien uno
 *   solo para las tres causas del codigo.
 * - `confirm` con un nombre en uso por un quiosco **activo** → `422` de
 *   validacion sobre `name`. No es un problema del codigo, y al administrador le
 *   cambia lo que tiene que hacer: cambiar el nombre, no pedir otro codigo.
 * - `pair` cuando la instalacion no puede atender mas solicitudes → `503`. No es
 *   un rechazo de nada que haya enviado quien llama: es «ahora no puedo», y por
 *   eso no es `429` ni lleva `Retry-After` que prometer.
 */
final class PairingController extends Controller
{
    /**
     * `POST /api/v1/kiosk/pair` — la tablet pide emparejarse.
     *
     * `201` porque nace un recurso: la solicitud. No tiene URL propia y no la
     * tendra —un `GET /kiosk/pair/{id}` seria una via publica para preguntar por
     * solicitudes ajenas— asi que tampoco lleva `Location`.
     */
    public function request(RequestPairingRequest $request, RequestPairing $pairing): JsonResponse
    {
        try {
            $ticket = $pairing->handle($request->toCommand());
        } catch (PairingUnavailable) {
            // La instalacion tiene demasiadas solicitudes vivas, o no queda
            // codigo libre que sortear. **Desde fuera son el mismo sintoma** y
            // la misma respuesta; el motivo real esta en el log del servidor.
            //
            // `503` y no `429`: aquel dice «vas demasiado rapido TU» y lo arregla
            // quien lo recibe, y la tablet que llega aqui casi nunca es la
            // culpable — del abuso por origen ya se encargo el limitador. El
            // texto no dice que ocurre dentro, y la PWA reintenta sola: el hueco
            // aparece solo, porque cada peticion purga las caducadas (regla
            // dura 19).
            return ProblemDetails::serviceUnavailable(
                'No se puede emparejar ahora mismo. Vuelve a intentarlo en unos minutos.',
            );
        }

        return (new PairingRequestedResource($ticket))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * `POST /api/v1/kiosk/pair/claim` — la tablet sondea y recoge su token.
     */
    public function claim(ClaimPairingRequest $request, ClaimPairing $pairing): JsonResponse
    {
        $result = $pairing->handle($request->toCommand());

        if ($result->outcome === ClaimOutcome::Rejected) {
            return PairingRejectedResponse::make();
        }

        return (new PairingClaimResource($result))->response();
    }

    /**
     * `POST /api/v1/kiosk/pair/confirm` — el administrador teclea el codigo.
     */
    public function confirm(ConfirmPairingRequest $request, ConfirmPairing $pairing): JsonResponse
    {
        $confirmation = $pairing->handle($request->toCommand());

        if ($confirmation->nameTaken) {
            // `422` de validacion y no `409`: para quien rellena el formulario,
            // el campo que hay que corregir es `name` y el mensaje va colgado de
            // el, como en el resto de los formularios del panel.
            return ProblemDetails::validationFailed([
                'name' => [(string) __('kiosk.errors.device_name_taken')],
            ]);
        }

        if ($confirmation->outcome === ConfirmOutcome::Rejected || $confirmation->device === null) {
            return PairingCodeRejectedResponse::make();
        }

        // El recurso entero y no solo el dispositivo: la respuesta lleva ademas
        // de que solicitud vino, para que quien acaba de teclear seis digitos
        // pueda contrastarlo con la tablet que tiene delante.
        return (new PairingConfirmedResource($confirmation))->response();
    }
}
