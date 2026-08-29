<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\Query\EmployeeQueries;
use App\Modules\Workforce\Application\UseCase\RegisterEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\UpdateEmployeeHandler;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Http\Request\IndexEmployeeRequest;
use App\Modules\Workforce\Http\Request\StoreEmployeeRequest;
use App\Modules\Workforce\Http\Request\UpdateEmployeeRequest;
use App\Modules\Workforce\Http\Resource\EmployeeProvisionedResource;
use App\Modules\Workforce\Http\Resource\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Plantilla: listado, ficha, alta y modificacion (RF-GP-01).
 *
 * El controlador no decide nada: valida y autoriza con el `FormRequest`, invoca
 * el caso de uso y serializa con el `Resource` (doc 02 §3.5). La baja tiene su
 * propio controlador porque no es una modificacion mas.
 *
 * **El alcance por departamento de RF-ID-03 se aplica de dos formas distintas, y
 * la diferencia es deliberada:**
 *
 * - En el **listado**, filtrando en la consulta. No hay `403`: un responsable ve a
 *   su gente y no se entera de que existe mas. Un `403` al listar convertiria la
 *   pantalla de plantilla de un responsable en un error permanente.
 * - En la **ficha**, comprobando el recurso ya cargado y respondiendo `403` con
 *   asiento en `audit_log` (escenario «Aislamiento por departamento» del doc 01
 *   §11). Aqui si hay un sujeto identificable al que apuntar en el trail.
 */
final class EmployeeController extends Controller
{
    public function index(
        IndexEmployeeRequest $request,
        EmployeeQueries $queries,
        ScopeGuard $scope,
    ): JsonResponse {
        $page = $queries->page(
            // RF-ID-03: el alcance entra en la consulta, no filtra despues. Va
            // primero por lo mismo que en el puerto: es la acotacion que quien
            // llama no puede elegir.
            scope: $scope->scopeOf($request->user()),
            departmentId: $request->departmentFilter(),
            status: $request->statusFilter(),
            search: $request->searchTerm(),
            pinStatus: $request->pinStatusFilter(),
            page: $request->page(),
            perPage: $request->perPage(),
        );

        // El estado del PIN de la pagina entera en una sola consulta: pintarlo
        // fila a fila serian cien consultas en un listado de cien personas.
        $pinStatuses = $queries->pinStatusesFor($page['items']);

        return response()->json([
            'data' => array_map(
                static fn (Employee $employee): array => (new EmployeeResource(
                    $employee,
                    $pinStatuses[$employee->uuid] ?? PinStatus::Pending,
                ))->toArray($request),
                $page['items'],
            ),
            'meta' => [
                'page' => $page['page'],
                'per_page' => $page['per_page'],
                'total' => $page['total'],
                'total_pages' => $page['total_pages'],
            ],
        ]);
    }

    public function show(
        Request $request,
        string $uuid,
        EmployeeQueries $queries,
        ScopeGuard $scope,
    ): JsonResponse {
        // Sin `FormRequest` porque no hay nada que validar, pero con policy: la
        // regla dura 18 no admite endpoints sin autorizacion comprobada.
        Gate::authorize('view', Employee::class);

        $employee = $queries->find($uuid);

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        // RF-ID-03, y **despues** del 404 a proposito: un responsable que se
        // equivoca de identificador tiene que recibir «eso no existe» y no un
        // asiento de intento fuera de alcance a nombre de nadie. El orden inverso
        // llenaria `audit_log` de erratas.
        $scope->ensureReaches(
            $scope->scopeOf($request->user()),
            $employee->departmentId,
            'employee_profile',
            $employee->uuid,
        );

        return (new EmployeeResource($employee, $queries->pinStatus($uuid)))->response();
    }

    public function store(
        StoreEmployeeRequest $request,
        RegisterEmployeeHandler $handler,
    ): JsonResponse {
        // El alta emite el PIN en la misma transaccion (RF-ID-09): la respuesta
        // lleva la ficha y el PIN, y es la unica vez que ese PIN se muestra.
        $registered = $handler->handle($request->toCommand());

        return (new EmployeeProvisionedResource($registered))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(
        UpdateEmployeeRequest $request,
        string $uuid,
        UpdateEmployeeHandler $handler,
        EmployeeQueries $queries,
    ): JsonResponse {
        $employee = $handler->handle($request->toCommand($uuid));

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        return (new EmployeeResource($employee, $queries->pinStatus($uuid)))->response();
    }
}
