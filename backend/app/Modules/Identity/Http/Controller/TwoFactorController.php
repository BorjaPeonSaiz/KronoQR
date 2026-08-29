<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\ConfirmTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\EnrolTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\VerifyTwoFactorHandler;
use App\Modules\Identity\Http\Request\TwoFactorCodeRequest;
use App\Modules\Identity\Http\Request\TwoFactorEnrolmentRequest;
use App\Modules\Identity\Http\Resource\SessionResource;
use App\Modules\Identity\Http\Resource\TwoFactorEnrolmentResource;
use Illuminate\Http\JsonResponse;

/**
 * Los tres endpoints del segundo factor (RF-ID-01, RS-06).
 *
 * **Un controlador con tres metodos y no tres controladores de invocacion unica**,
 * al contrario que el resto de este modulo: los tres son pasos del **mismo** flujo
 * y comparten la sesion pendiente como entrada. Separarlos repartiria por tres
 * ficheros una secuencia que solo se entiende junta.
 *
 * Ninguno decide nada: valida y autoriza con el `FormRequest`, invoca el caso de
 * uso y serializa. Los desenlaces de error —codigo invalido, bloqueo, cuenta ya
 * dada de alta— son excepciones que el renderizador global convierte en
 * `application/problem+json`.
 *
 * **`verify` y `confirm` devuelven exactamente lo mismo**, `Session`, y eso es
 * deliberado: para el panel, completar el segundo factor y activarlo por primera
 * vez terminan igual —se entra—, asi que la pantalla que los consume es una sola.
 */
final class TwoFactorController extends Controller
{
    /**
     * `POST /api/v1/auth/2fa/verify`.
     */
    public function verify(TwoFactorCodeRequest $request, VerifyTwoFactorHandler $handler): JsonResponse
    {
        $outcome = $handler->handle($request->toCommand());

        return (new SessionResource($outcome))->response();
    }

    /**
     * `POST /api/v1/auth/2fa/enrol`.
     *
     * El secreto viaja aqui **una sola vez**. Si quien lo pide cierra la pantalla
     * antes de escanear el QR, repite el alta: no hay ninguna via para volver a
     * leer el mismo secreto, y no la hay a proposito.
     */
    public function enrol(TwoFactorEnrolmentRequest $request, EnrolTwoFactorHandler $handler): JsonResponse
    {
        return (new TwoFactorEnrolmentResource($handler->handle($request->toCommand())))->response();
    }

    /**
     * `POST /api/v1/auth/2fa/confirm`.
     */
    public function confirm(TwoFactorCodeRequest $request, ConfirmTwoFactorHandler $handler): JsonResponse
    {
        $outcome = $handler->handle($request->toCommand());

        return (new SessionResource($outcome))->response();
    }
}
