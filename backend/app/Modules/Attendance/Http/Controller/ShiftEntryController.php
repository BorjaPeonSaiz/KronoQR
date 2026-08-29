<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\Port\ShiftEntrySubject;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Http\Request\AddShiftEntryRequest;
use App\Modules\Attendance\Http\Request\CorrectShiftEntryRequest;
use App\Modules\Attendance\Http\Resource\CorrectedShiftEntryResource;
use App\Modules\Attendance\Http\Support\CorrectionTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\EmployeeScopeDirectory;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alta manual y correccion de tramos (RF-PA-04, RN-13, RL-04).
 *
 * Delgado a proposito, como el del escaneo: valida —lo hace el `FormRequest`—,
 * autoriza —lo hace la policy desde ahi—, construye el comando, invoca el caso
 * de uso y devuelve el `Resource`. **Ninguna regla de negocio vive aqui**: quien
 * conserva la version anterior, encadena la nueva y comprueba RN-01, RN-02,
 * RN-03 y RN-05 es el agregado `WorkDay`.
 *
 * **No hay ningun `try`/`catch`.** Las excepciones de dominio y de aplicacion se
 * traducen a `problem+json` en `bootstrap/app.php`, que es donde vive esa
 * traduccion para toda la API. Repartirla por los controladores acabaria con dos
 * endpoints devolviendo codigos distintos para el mismo conflicto.
 *
 * **El alcance por departamento se comprueba antes de tocar nada** (RF-ID-03).
 * Un `responsable_departamento` da de alta y corrige tramos de la gente de sus
 * departamentos, y recibe `403` —con asiento en `audit_log`— para cualquier otra
 * persona. En el alta, el sujeto viene en el cuerpo; en la correccion se resuelve
 * del propio tramo, porque quien corrige solo conoce su identificador.
 *
 * **Ni el alta ni la correccion aceptan `Idempotency-Key`.** No es un olvido: la
 * idempotencia por `scan_id` es del quiosco, que reenvia desde una cola sin
 * saber si su peticion llego (regla dura 8). Aqui quien llama es una persona
 * ante un formulario en el panel, con la respuesta delante; y una correccion
 * repetida no es un duplicado silencioso, es un `409` porque la version que
 * envio ya no es la vigente (ADR-035).
 */
final class ShiftEntryController extends Controller
{
    /**
     * `POST /api/v1/shift-entries` — el tramo que nunca se ficho.
     */
    public function store(
        AddShiftEntryRequest $request,
        AddShiftEntryHandler $handler,
        CorrectionTelemetry $telemetry,
        ScopeGuard $scope,
        EmployeeScopeDirectory $employees,
    ): JsonResponse {
        $command = $request->toCommand();

        $scope->ensureReaches(
            $scope->scopeOf($request->user()),
            $employees->departmentIdOf($command->employeeUuid),
            'shift_entry',
            $command->employeeUuid,
            ['operation' => 'add'],
        );

        $corrected = $telemetry->measure(
            'add',
            $command->reason->code->value,
            static fn () => $handler->handle($command),
        );

        // `201` y no `200`: se ha creado un tramo que antes no existia. El
        // cliente lo distingue del `PATCH`, que rectifica uno que ya estaba.
        return (new CorrectedShiftEntryResource($corrected))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * `PATCH /api/v1/shift-entries/{uuid}` — rectificar las marcas.
     *
     * Devuelve un `shift_entry_uuid` **distinto** del `{uuid}` recibido: la
     * version corregida es una fila nueva (ADR-035). El anterior viaja en
     * `superseded_shift_entry_uuid` para que el panel enlace el historico sin
     * una segunda consulta.
     */
    public function update(
        CorrectShiftEntryRequest $request,
        string $uuid,
        CorrectShiftHandler $handler,
        CorrectionTelemetry $telemetry,
        ScopeGuard $scope,
        EmployeeScopeDirectory $employees,
        ShiftEntrySubject $subjects,
    ): JsonResponse {
        $employeeUuid = $subjects->employeeUuidOf($uuid);

        $scope->ensureReaches(
            $scope->scopeOf($request->user()),
            $employeeUuid === null ? null : $employees->departmentIdOf($employeeUuid),
            'shift_entry',
            $employeeUuid,
            ['operation' => 'correct'],
        );

        $command = $request->toCommand($uuid);

        $corrected = $telemetry->measure(
            'correct',
            $command->reason->code->value,
            static fn () => $handler->handle($command),
        );

        return (new CorrectedShiftEntryResource($corrected))->response();
    }
}
