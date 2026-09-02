<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\SiteQueries;
use App\Modules\Workforce\Application\UseCase\CreateSiteHandler;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Http\Request\CreateInstallationSiteRequest;
use App\Modules\Workforce\Http\Request\UpdateSiteRequest;
use App\Modules\Workforce\Http\Resource\SiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El centro de trabajo de la instalacion: `GET` y `PATCH /api/v1/site`.
 *
 * Recurso singular sin identificador en la ruta (ADR-040): una licencia es un
 * hotel. **Sin alta ni lista en `/site`** y sin baja, porque `shift_entries`
 * cuelga de el.
 *
 * `404` solo antes de la puesta en marcha: es el unico momento en que la
 * instalacion no tiene centro.
 *
 * ## El alta esta aqui, pero su ruta es `POST /api/v1/setup/site`
 *
 * Y no `POST /api/v1/site`, que sigue respondiendo `405`. La diferencia no es
 * cosmetica: un alta colgada del recurso singular seria un alta **permanente**
 * de centros —la primera pieza del multicentro que ADR-040 cerro, y algo que el
 * Anexo B del doc 01 niega por escrito—, mientras que bajo `/setup` es lo que
 * de verdad es: un acto irrepetible de puesta en marcha, que solo tiene exito
 * mientras no haya centro.
 *
 * El metodo vive en este controlador y no en uno de `Product` porque el recurso
 * es de `Workforce`: el prefijo de la ruta agrupa el asistente en el contrato,
 * no mueve la logica de modulo.
 */
final class SiteController extends Controller
{
    public function show(Request $request, SiteQueries $queries): JsonResponse
    {
        Gate::authorize('view', Site::class);

        $site = $queries->installationSite();

        if ($site === null) {
            throw new NotFoundHttpException;
        }

        return (new SiteResource($site))->response();
    }

    /**
     * `POST /api/v1/setup/site` — el alta de la puesta en marcha (RF-PD-03).
     *
     * `201` porque se ha creado un recurso. Que ya exista es
     * `SiteAlreadyConfigured`, que el renderizador global convierte en `409` por
     * ser un `WorkforceConflict`.
     */
    public function store(CreateInstallationSiteRequest $request, CreateSiteHandler $handler): JsonResponse
    {
        $site = $handler->handle($request->toCommand());

        return (new SiteResource($site))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateSiteRequest $request, UpdateSiteHandler $handler): JsonResponse
    {
        $site = $handler->handle($request->toCommand());

        if ($site === null) {
            throw new NotFoundHttpException;
        }

        return (new SiteResource($site))->response();
    }
}
