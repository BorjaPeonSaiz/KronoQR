<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\EmployeeQueries;
use App\Modules\Workforce\Application\UseCase\RehiredEmployee;
use App\Modules\Workforce\Application\UseCase\RehireEmployeeBatchHandler;
use App\Modules\Workforce\Http\Request\RehireEmployeesRequest;
use App\Modules\Workforce\Http\Resource\RehiredEmployeeResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/employees/rehire` — readmision de varias personas con una fecha
 * de alta comun, todo o nada (RN-27, ADR-062 §6). Si alguna no se puede
 * readmitir, `422` con un error por posicion y nada escrito.
 */
final class RehireEmployeesController extends Controller
{
    public function __invoke(
        RehireEmployeesRequest $request,
        RehireEmployeeBatchHandler $handler,
        EmployeeQueries $queries,
    ): JsonResponse {
        $rehired = $handler->handle($request->toCommand());

        return response()->json([
            'rehired' => array_map(
                static fn (RehiredEmployee $one): array => (new RehiredEmployeeResource($one, $queries->pinStatus($one->employee->uuid)))
                    ->toArray($request),
                $rehired,
            ),
        ]);
    }
}
