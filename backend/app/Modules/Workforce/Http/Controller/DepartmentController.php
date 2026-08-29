<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\DepartmentQueries;
use App\Modules\Workforce\Application\UseCase\CreateDepartmentHandler;
use App\Modules\Workforce\Application\UseCase\RenameDepartmentHandler;
use App\Modules\Workforce\Domain\Model\Department;
use App\Modules\Workforce\Http\Request\IndexDepartmentRequest;
use App\Modules\Workforce\Http\Request\StoreDepartmentRequest;
use App\Modules\Workforce\Http\Request\UpdateDepartmentRequest;
use App\Modules\Workforce\Http\Resource\DepartmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Departamentos de cada centro.
 *
 * **Sin `destroy`**, por el mismo motivo que los centros: un departamento con
 * empleados o con incidencias asignadas no puede desaparecer, y la baja logica
 * de un departamento no existe en el esquema de 1.3. Vaciarlo primero y
 * renombrarlo es lo que hay, y es lo honesto.
 */
final class DepartmentController extends Controller
{
    public function index(IndexDepartmentRequest $request, DepartmentQueries $queries): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (Department $department): array => (new DepartmentResource($department))->toArray($request),
                $queries->all(),
            ),
        ]);
    }

    public function show(Request $request, int $id, DepartmentQueries $queries): JsonResponse
    {
        Gate::authorize('view', Department::class);

        $department = $queries->find($id);

        if ($department === null) {
            throw new NotFoundHttpException;
        }

        return (new DepartmentResource($department))->response();
    }

    public function store(StoreDepartmentRequest $request, CreateDepartmentHandler $handler): JsonResponse
    {
        $department = $handler->handle($request->toCommand());

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateDepartmentRequest $request, int $id, RenameDepartmentHandler $handler): JsonResponse
    {
        $department = $handler->handle($request->toCommand($id));

        if ($department === null) {
            throw new NotFoundHttpException;
        }

        return (new DepartmentResource($department))->response();
    }
}
