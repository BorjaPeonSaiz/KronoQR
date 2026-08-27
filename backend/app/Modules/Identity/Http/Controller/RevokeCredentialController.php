<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\RevokeCredential;
use App\Modules\Identity\Http\Request\RevokeCredentialRequest;
use App\Modules\Identity\Http\Resource\CredentialResource;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/credentials/{uuid}/revoke` — revocacion (RF-QR-03).
 *
 * **Endpoint propio y no un `PATCH` con `status=revoked`**, por lo mismo que la
 * baja de empleado: revocar deja a una persona sin poder fichar y exige un
 * motivo. Un verbo que puede hacer eso de pasada acaba haciendolo.
 *
 * Revocar una credencial ya revocada responde `409`: no es idempotente a
 * proposito. Sobrescribir la primera revocacion cambiaria el motivo y el momento
 * que ya constan en `audit_log`.
 */
final class RevokeCredentialController extends Controller
{
    public function __invoke(
        RevokeCredentialRequest $request,
        string $uuid,
        RevokeCredential $handler,
    ): JsonResponse {
        $actor = $request->user();

        $revoked = $handler->handle($request->toCommand($uuid, $actor instanceof User ? $actor->id : null));

        if ($revoked === null) {
            throw new NotFoundHttpException;
        }

        return (new CredentialResource($revoked))->response();
    }
}
