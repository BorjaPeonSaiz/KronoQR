<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Attendance\Http\Request\VoidShiftEntryRequest;
use App\Modules\Attendance\Http\Resource\CorrectedShiftEntryResource;
use App\Modules\Attendance\Http\Support\CorrectionTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/shift-entries/{uuid}/void` — declarar que un tramo no ocurrio
 * (RF-PA-04, ADR-026).
 *
 * **Controlador propio y no un tercer metodo del anterior**, por lo mismo que
 * `OffboardEmployeeController` no es un metodo de `EmployeeController`: anular
 * tiene su propia policy —`rrhh+`, no `manager+`—, su propia accion en
 * `audit_log` y su propio significado ante Inspeccion. Un endpoint que comparte
 * clase con otro acaba compartiendo comprobaciones.
 *
 * **Es `POST` y no `DELETE`.** En esta API no hay ningun `DELETE` (regla dura 5):
 * la fila se queda en la tabla con sus marcas, su autor y su motivo, y lo unico
 * que cambia es que sale del conjunto vigente.
 */
final class VoidShiftEntryController extends Controller
{
    public function __invoke(
        VoidShiftEntryRequest $request,
        string $uuid,
        VoidShiftHandler $handler,
        CorrectionTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand($uuid);

        $corrected = $telemetry->measure(
            'void',
            $command->reason->code->value,
            static fn () => $handler->handle($command),
        );

        return (new CorrectedShiftEntryResource($corrected))->response();
    }
}
