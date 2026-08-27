<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\IssueCredential;
use App\Modules\Identity\Http\Request\StoreCredentialRequest;
use App\Modules\Identity\Http\Resource\IssuedCredentialResource;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/credentials` — emision de credencial (RF-QR-01, RF-QR-03).
 *
 * El controlador no sabe que hay un HMAC detras: recibe un DTO, invoca el caso
 * de uso y devuelve un `Resource`. La firma, la clave y el hash del token viven
 * en `Identity/Domain` y en su adaptador.
 *
 * **La respuesta no lleva ningun QR** (ADR-034): emitir deja la credencial
 * pendiente de imprimir y el token se acuña dentro del PDF de la tarjeta, que es
 * de la tarea 1.10. Se conserva `Cache-Control: no-store` de todos modos: es
 * gestion de credenciales de personas identificadas y no tiene ningun sentido
 * que se quede en la cache de un proxy.
 */
final class CredentialController extends Controller
{
    public function store(StoreCredentialRequest $request, IssueCredential $handler): JsonResponse
    {
        $actor = $request->user();

        $issued = $handler->handle($request->toCommand($actor instanceof User ? $actor->id : null));

        if ($issued === null) {
            // Empleado inexistente. 404 y no 422: el recurso al que apunta la
            // peticion no esta, y decir «el UUID no es valido» seria describir
            // otro problema.
            throw new NotFoundHttpException;
        }

        return (new IssuedCredentialResource($issued))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Cache-Control', 'no-store');
    }
}
