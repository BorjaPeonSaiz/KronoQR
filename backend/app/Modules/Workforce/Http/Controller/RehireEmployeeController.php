<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\EmployeeQueries;
use App\Modules\Workforce\Application\UseCase\RehireEmployeeHandler;
use App\Modules\Workforce\Http\Request\RehireEmployeeRequest;
use App\Modules\Workforce\Http\Resource\RehiredEmployeeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/employees/{uuid}/rehire` — readmision de una persona de baja en
 * su misma ficha (RN-27, ADR-062 §6).
 *
 * Endpoint propio y no un `PATCH` con `status=active`: la readmision abre un
 * periodo nuevo, emite una tarjeta y deja asiento, y la base no admite la
 * transicion `terminated → active` por ningun otro camino (ADR-062 §2).
 */
final class RehireEmployeeController extends Controller
{
    public function __invoke(
        RehireEmployeeRequest $request,
        string $uuid,
        RehireEmployeeHandler $handler,
        EmployeeQueries $queries,
    ): JsonResponse {
        $rehired = $handler->handle($request->toCommand($uuid));

        if ($rehired === null) {
            throw new NotFoundHttpException;
        }

        return (new RehiredEmployeeResource($rehired, $queries->pinStatus($uuid)))->response();
    }
}
