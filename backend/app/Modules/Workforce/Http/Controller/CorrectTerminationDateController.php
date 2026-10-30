<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use App\Modules\Workforce\Application\UseCase\CorrectTerminationDateHandler;
use App\Modules\Workforce\Http\Request\CorrectTerminationDateRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/employees/{uuid}/employment-periods/{periodUuid}/termination-corrections`
 * — correccion auditada de la fecha de cese de un periodo con baja efectiva
 * (RN-27, RL-04, ADR-062 §7).
 *
 * Crea una correccion y nunca sobrescribe sin rastro (RN-13): la respuesta es
 * la correccion, `201`, con la fecha anterior y la nueva y sin el motivo.
 */
final class CorrectTerminationDateController extends Controller
{
    public function __invoke(
        CorrectTerminationDateRequest $request,
        string $uuid,
        string $periodUuid,
        CorrectTerminationDateHandler $handler,
    ): JsonResponse {
        $corrected = $handler->handle($request->toCommand($uuid, $periodUuid));

        if ($corrected === null) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'id' => $corrected->id,
            'employment_period_uuid' => $corrected->periodUuid,
            'previous_terminated_at' => $corrected->previousTerminatedOn,
            'terminated_at' => $corrected->terminatedOn,
            'corrected_at' => UtcInstant::of($corrected->correctedAt, UtcPrecision::Milliseconds),
        ], JsonResponse::HTTP_CREATED);
    }
}
