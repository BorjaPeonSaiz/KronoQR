<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Query\CurrentUserQuery;
use App\Modules\Identity\Http\Resource\ManagementUserResource;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/auth/me` — usuario, rol y ambito de la sesion en curso.
 *
 * Relee la cuenta en lugar de devolver lo que dice el token: un rol retirado o
 * una cuenta desactivada tienen que notarse en la peticion siguiente y no
 * cuando caduque la sesion.
 *
 * Si la cuenta ya no vale, responde `401` y no `404`: desde fuera es lo mismo
 * —no hay sesion— y el contrato de este endpoint solo tiene esos dos
 * desenlaces.
 */
final class CurrentUserController extends Controller
{
    /**
     * @throws AuthenticationException cuando la cuenta se ha desactivado con la sesion abierta
     */
    public function __invoke(Request $request, CurrentUserQuery $query): JsonResponse
    {
        $actor = $request->user();

        $user = $actor instanceof ManagementActor
            ? $query->handle($actor->actorUuid())
            : null;

        if ($user === null) {
            throw new AuthenticationException;
        }

        return (new ManagementUserResource($user))->response();
    }
}
