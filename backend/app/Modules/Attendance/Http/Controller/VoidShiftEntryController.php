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
 *
 * **Aqui NO se comprueba el alcance por departamento, y no es un olvido**
 * (RF-ID-03, tarea 2.1). Anular es `rrhh+` —`{admin, rrhh}`— y esos dos roles
 * alcanzan toda la plantilla por definicion: la comprobacion seria una rama
 * inalcanzable, que no protege nada y ademas no se puede probar. Al
 * `responsable_departamento` lo deja fuera la policy, y **eso** si tiene su
 * prueba negativa. El dia que anular deje de ser `rrhh+`, el guardian entra en
 * este metodo igual que en los dos de `ShiftEntryController`.
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
