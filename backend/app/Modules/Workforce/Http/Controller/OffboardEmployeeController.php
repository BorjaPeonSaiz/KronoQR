<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\EmployeeQueries;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Http\Request\OffboardEmployeeRequest;
use App\Modules\Workforce\Http\Resource\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/employees/{uuid}/offboard` — baja de empleado (RF-GP-03).
 *
 * **Endpoint propio y no un `PATCH` con `status=terminated`**: la baja lleva
 * fecha de cese obligatoria, cierra el registro horario de una persona y revoca
 * su credencial (RN-14). Un verbo que puede hacer eso por descuido acaba
 * haciendolo.
 *
 * **Nunca borra nada** (regla dura 5): la ficha y todo su historial siguen ahi.
 */
final class OffboardEmployeeController extends Controller
{
    public function __invoke(
        OffboardEmployeeRequest $request,
        string $uuid,
        OffboardEmployeeHandler $handler,
        EmployeeQueries $queries,
    ): JsonResponse {
        $employee = $handler->handle($request->toCommand($uuid));

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        // El PIN de quien causa baja no se revoca aqui: la ficha conserva su
        // estado y quien deja el hotel sigue pudiendo consultar su registro
        // horario (RL-05, RL-02). Cerrar esa puerta es una decision de la Fase 2
        // y no un efecto colateral de este endpoint.
        return (new EmployeeResource($employee, $queries->pinStatus($uuid)))->response();
    }
}
