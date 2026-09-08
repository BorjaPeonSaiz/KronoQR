<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\Command\RevokeSupportAccessCommand;
use App\Modules\Product\Application\UseCase\GrantSupportAccessHandler;
use App\Modules\Product\Application\UseCase\ListSupportGrantsHandler;
use App\Modules\Product\Application\UseCase\RevokeSupportAccessHandler;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportRevocationOutcome;
use App\Modules\Product\Http\Request\GrantSupportAccessRequest;
use App\Modules\Product\Http\Resource\IssuedSupportGrantResource;
use App\Modules\Product\Http\Resource\SupportGrantCollectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las tres rutas de `/api/v1/support/grants` (Anexo B del doc 01, **RF-PD-11**,
 * RL-18, ADR-020).
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa. **Ninguna
 * decision vive aqui.** La caducidad la calcula el dominio con el reloj
 * inyectado, el token lo emite el adaptador, el asiento lo escribe el listener de
 * `Compliance` y el texto del aviso lo compone el panel con sus traducciones.
 *
 * ## `DELETE` idempotente, `404` solo si no existe
 *
 * Revocar una concesion ya revocada o caducada devuelve `204` y no vuelve a
 * auditar: la segunda pulsacion de un boton no es un hecho nuevo. `404`
 * unicamente cuando el UUID no corresponde a ninguna concesion, y eso no revela
 * nada — quien pregunta ya es administrador de esta instalacion.
 *
 * ## Ninguna de las tres degrada con la licencia caducada
 *
 * Regla dura 15 y ADR-019. Es mas: **es cuando mas falta hacen**. La incidencia
 * que hay que resolver puede ser justamente que la renovacion no se activa, y
 * cerrar la puerta por la que entra quien la va a arreglar seria un producto que
 * se apaga solo.
 */
final class SupportGrantController extends Controller
{
    public function index(ListSupportGrantsHandler $grants): JsonResponse
    {
        // El sujeto es el modelo de dominio y no una fila: la policy no autoriza
        // sobre una concesion concreta —todas son iguales ante ella— sino sobre
        // «los accesos de soporte de esta instalacion».
        Gate::authorize('viewAny', SupportGrant::class);

        return (new SupportGrantCollectionResource($grants->handle(), $grants->asOf()))->response();
    }

    public function store(
        GrantSupportAccessRequest $request,
        GrantSupportAccessHandler $grant,
        ListSupportGrantsHandler $grants,
    ): JsonResponse {
        // La policy la comprueba el `FormRequest` con `Gate::allows('grant')`,
        // igual que la activacion de licencia: asi un cuerpo invalido no llega a
        // saber si tenia permiso.
        $issued = $grant->handle($request->toCommand());

        return (new IssuedSupportGrantResource($issued, $grants->asOf()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(string $uuid, Request $request, RevokeSupportAccessHandler $revoke): Response
    {
        Gate::authorize('revoke', SupportGrant::class);

        $outcome = $revoke->handle(new RevokeSupportAccessCommand($uuid, self::actorUserId($request)));

        if ($outcome === SupportRevocationOutcome::NotFound) {
            throw new NotFoundHttpException;
        }

        return response()->noContent();
    }

    private static function actorUserId(Request $request): ?int
    {
        $identifier = $request->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
