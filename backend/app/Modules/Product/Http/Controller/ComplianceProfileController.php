<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\GetComplianceProfileHandler;
use App\Modules\Product\Application\UseCase\UpdateComplianceProfileHandler;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Http\Request\UpdateComplianceProfileRequest;
use App\Modules\Product\Http\Resource\ComplianceProfileResource;
use App\Modules\Product\Http\Support\ComplianceProfileTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET` y `PATCH /api/v1/compliance-profile` — los umbrales **legales** del
 * centro (**RF-PD-07**, regla dura 13).
 *
 * Recurso singular sin identificador en la ruta, como `/site` (ADR-040): hay un
 * centro por instalacion y por tanto un perfil vigente. No hay alta ni baja de
 * perfiles: un segundo perfil no lo leeria nadie.
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa. **Ninguna
 * decision vive aqui.** Que valores admite cada campo lo dice el catalogo de
 * campos, que campos cambian de verdad lo decide el caso de uso, y el asiento de
 * `audit_log` lo escribe el listener de `Compliance`.
 *
 * `404` solo antes de la puesta en marcha (RF-PD-03) o si alguien borro la fila
 * del perfil: los dos son estados de la instalacion que se arreglan una vez.
 */
final class ComplianceProfileController extends Controller
{
    public function show(GetComplianceProfileHandler $handler, ComplianceProfileTelemetry $telemetry): JsonResponse
    {
        Gate::authorize('view', ComplianceProfileSnapshot::class);

        $profile = $telemetry->measureRead(
            static fn (): ?ComplianceProfileSnapshot => $handler->handle(),
        );

        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return (new ComplianceProfileResource($profile))->response();
    }

    public function update(
        UpdateComplianceProfileRequest $request,
        UpdateComplianceProfileHandler $handler,
        ComplianceProfileTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand();

        $profile = $telemetry->measureUpdate(
            array_keys($command->values),
            static fn (): ?ComplianceProfileSnapshot => $handler->handle($command),
        );

        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return (new ComplianceProfileResource($profile))->response();
    }
}
