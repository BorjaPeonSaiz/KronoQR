<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\SiteQueries;
use App\Modules\Workforce\Application\UseCase\CreateSiteHandler;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Http\Request\StoreSiteRequest;
use App\Modules\Workforce\Http\Request\UpdateSiteRequest;
use App\Modules\Workforce\Http\Resource\SiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Centros de trabajo.
 *
 * **Sin `destroy`, y no por olvido.** Nada se borra (regla dura 5): un centro
 * con empleados o con tramos no puede desaparecer sin llevarse por delante el
 * registro horario que la ley obliga a conservar cuatro anos (RL-02). La tabla
 * de 1.3 tampoco tiene columna de baja logica para centros, asi que añadir un
 * `DELETE` exigiria un cambio de esquema y una decision de producto, no un
 * verbo mas en este controlador.
 */
final class SiteController extends Controller
{
    public function index(Request $request, SiteQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', Site::class);

        return response()->json([
            'data' => array_map(
                static fn (Site $site): array => (new SiteResource($site))->toArray($request),
                $queries->all(),
            ),
        ]);
    }

    public function show(Request $request, int $id, SiteQueries $queries): JsonResponse
    {
        Gate::authorize('view', Site::class);

        $site = $queries->find($id);

        if ($site === null) {
            throw new NotFoundHttpException;
        }

        return (new SiteResource($site))->response();
    }

    public function store(StoreSiteRequest $request, CreateSiteHandler $handler): JsonResponse
    {
        $site = $handler->handle($request->toCommand());

        return (new SiteResource($site))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateSiteRequest $request, int $id, UpdateSiteHandler $handler): JsonResponse
    {
        $site = $handler->handle($request->toCommand($id));

        if ($site === null) {
            throw new NotFoundHttpException;
        }

        return (new SiteResource($site))->response();
    }
}
