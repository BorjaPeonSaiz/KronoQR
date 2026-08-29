<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\SiteQueries;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Model\Site;
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
 * hotel. No hay alta por HTTP —la hace el asistente de puesta en marcha— ni
 * baja, porque `shift_entries` cuelga de el.
 *
 * `404` solo antes de la puesta en marcha: es el unico momento en que la
 * instalacion no tiene centro.
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

    public function update(UpdateSiteRequest $request, UpdateSiteHandler $handler): JsonResponse
    {
        $site = $handler->handle($request->toCommand());

        if ($site === null) {
            throw new NotFoundHttpException;
        }

        return (new SiteResource($site))->response();
    }
}
