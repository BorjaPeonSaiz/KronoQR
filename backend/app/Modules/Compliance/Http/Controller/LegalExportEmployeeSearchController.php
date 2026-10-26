<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Application\UseCase\SearchLegalExportEmployees;
use App\Modules\Compliance\Http\Request\SearchLegalExportEmployeesRequest;
use App\Modules\Compliance\Http\Resource\LegalExportEmployeeMatchesResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/reports/legal-export/employees/search` — la persona de la
 * exportacion para la Inspeccion, por su nombre o su DNI (RF-IN-05).
 *
 * Recibe el criterio ya autorizado y validado, invoca el caso de uso —que mide,
 * busca y deja el asiento antes de devolver nada— y responde con el Resource.
 *
 * **`Cache-Control: no-store, private`**: el cuerpo es una lista nominal y no
 * debe quedarse en la cache del navegador ni en la de un proxy intermedio.
 */
final class LegalExportEmployeeSearchController extends Controller
{
    public function __invoke(
        SearchLegalExportEmployeesRequest $request,
        SearchLegalExportEmployees $search,
    ): JsonResponse {
        $matches = $search->handle($request->toSearch());

        return new LegalExportEmployeeMatchesResource($matches)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
