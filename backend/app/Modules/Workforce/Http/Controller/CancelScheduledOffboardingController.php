<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\EmployeeQueries;
use App\Modules\Workforce\Application\UseCase\CancelScheduledOffboardingHandler;
use App\Modules\Workforce\Http\Request\CancelScheduledOffboardingRequest;
use App\Modules\Workforce\Http\Resource\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/employees/{uuid}/offboard/cancel` — anula una baja programada
 * (RN-14, RF-GP-03, ADR-062 §3).
 *
 * Verbo propio y no un `PATCH` con `terminated_at: null`: quitar la fecha de cese
 * deja asiento propio y solo cabe mientras el cese no haya pasado, y un `PATCH`
 * de la ficha no escribe nunca esa columna (ADR-046 §3).
 */
final class CancelScheduledOffboardingController extends Controller
{
    public function __invoke(
        CancelScheduledOffboardingRequest $request,
        string $uuid,
        CancelScheduledOffboardingHandler $handler,
        EmployeeQueries $queries,
    ): JsonResponse {
        $employee = $handler->handle($request->toCommand($uuid));

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        return (new EmployeeResource($employee, $queries->pinStatus($uuid)))->response();
    }
}
