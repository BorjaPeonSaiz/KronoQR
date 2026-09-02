<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\CreateFirstAdministratorHandler;
use App\Modules\Identity\Http\Request\CreateFirstAdministratorRequest;
use App\Modules\Identity\Http\Resource\TwoFactorChallengeResource;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * `POST /api/v1/setup/administrator` — el primer administrador de la
 * instalacion (**RF-PD-03**, paso 1 del asistente).
 *
 * **Vive en `Identity` aunque su ruta cuelgue de `/setup`.** El prefijo agrupa
 * el asistente **en el contrato**, para que el panel encuentre junto lo que
 * ocurre una sola vez; no es una razon para sacar de `Identity` el alta de una
 * cuenta de gestion, y ademas `Product` no podria importarla (doc 02 §1.6).
 *
 * **Devuelve `201` con un `TwoFactorChallenge`**, la misma forma que el `202` de
 * `/auth/login`. `201` porque aqui se ha creado un recurso —la cuenta— y `202`
 * alli porque no se creo nada; el cuerpo es identico a proposito, para que el
 * panel reutilice la pantalla del QR del autenticador sin una segunda variante.
 *
 * El controlador no decide nada: valida, invoca y serializa. Que ya haya cuentas
 * es una excepcion que el renderizador global convierte en `409`.
 */
final class FirstAdministratorController extends Controller
{
    public function __invoke(
        CreateFirstAdministratorRequest $request,
        CreateFirstAdministratorHandler $handler,
        LoggerInterface $logger,
    ): JsonResponse {
        $outcome = $handler->handle($request->toCommand());

        // NI EL CORREO NI EL NOMBRE (regla dura 21). El UUID publico basta para
        // correlacionar esta linea con el asiento `role_assignment.changed` y
        // con el `auth.two_factor_enabled` que llegara despues, y es el unico
        // identificador de una persona que puede viajar en el paquete de
        // diagnostico (ADR-020).
        $logger->info('setup.first_administrator_created', [
            'user_uuid' => $outcome->user->uuid,
        ]);

        return (new TwoFactorChallengeResource($outcome))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
