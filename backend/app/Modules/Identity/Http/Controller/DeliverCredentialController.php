<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Http\Request\DeliverCredentialRequest;
use App\Modules\Identity\Http\Resource\CredentialResource;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/credentials/{uuid}/deliver` — registro de entrega (RF-QR-06).
 *
 * **El responsable sale de la sesion y no del cuerpo.** Es la unica forma de que
 * el registro valga como firma: si se pudiera declarar, cualquiera podria dejar
 * constancia de una entrega a nombre de otro.
 *
 * **Endpoint propio y no un `PATCH` con `delivered=true`**, por lo mismo que la
 * revocacion y la baja de empleado: es un acto con consecuencias legales y su
 * asiento de auditoria. Un verbo que puede hacer eso de pasada acaba haciendolo.
 */
final class DeliverCredentialController extends Controller
{
    public function __invoke(
        DeliverCredentialRequest $request,
        string $uuid,
        DeliverCredential $handler,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            // No deberia ocurrir —`auth:sanctum` va delante—, pero una entrega
            // sin responsable no se escribe: la columna es `NOT NULL` y el
            // registro no serviria para lo unico para lo que existe.
            throw new NotFoundHttpException;
        }

        $delivered = $handler->handle($request->toCommand($uuid, $actor->id));

        if ($delivered === null) {
            throw new NotFoundHttpException;
        }

        return (new CredentialResource($delivered))
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
