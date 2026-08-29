<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Http\Request\ListEmployeeWorkDaysRequest;
use App\Modules\Reporting\Http\Resource\EmployeeWorkDaysResource;
use App\Modules\Reporting\Http\Support\JournalTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\EmployeeScopeDirectory;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/employees/{uuid}/workdays` — el registro horario de una persona
 * (RF-PA-03).
 *
 * Delgado a proposito, como el resto: valida y autoriza el `FormRequest`,
 * consulta el caso de uso y serializa el `Resource`. **Ninguna decision vive
 * aqui**: el rango por omision lo resuelve
 * {@see ReadEmployeeWorkDays} —que es quien tiene el reloj y la zona del
 * centro—, el total de cada jornada lo deriva el objeto de valor (RN-06) y la
 * conversion a hora local la hace el `Resource`.
 *
 * **Solo lee, y no puede hacer otra cosa.** Rectificar es `PATCH
 * /api/v1/shift-entries/{uuid}`, que vive en `Attendance` y exige el ambito
 * `attendance:correct`; este endpoint solo alcanza `attendance:read`.
 *
 * **No hay ningun `try`/`catch`.** Un empleado inexistente es un `404` y un
 * rango imposible un `422`, y las dos traducciones viven en `bootstrap/app.php`,
 * que es donde estan las de toda la API. Repartirlas por los controladores acaba
 * con dos endpoints devolviendo codigos distintos para lo mismo.
 *
 * **La constancia del acceso no se escribe aqui** (RS-05): la escribe el caso de
 * uso, dentro. Si dependiera de una linea de este metodo, el dia que exista un
 * segundo camino hacia el mismo registro —el portal de la 1.11, la exportacion
 * legal de la 1.17— habria que acordarse de repetirla.
 *
 * **El alcance por departamento SI se comprueba aqui** (RF-ID-03), y antes de
 * invocar nada: un responsable de Cocina que pide el registro de alguien de
 * Recepcion recibe `403` y el intento queda en `audit_log` con el
 * `employee_uuid` del recurso (escenario «Aislamiento por departamento» del doc
 * 01 §11). Va en el controlador y no en el caso de uso porque el caso de uso lo
 * comparten dos caminos —este y el propio empleado por el portal— y el alcance
 * solo tiene sentido en uno.
 *
 * **Un UUID que no existe le responde `403` a un responsable y `404` a RRHH**, y
 * es deliberado: para quien tiene alcance acotado, «esa persona no esta a tu
 * alcance» es la respuesta honesta exista o no, y distinguirlo convertiria el
 * endpoint en un comprobador de que identificadores existen para quien no puede
 * verlos.
 */
final class EmployeeWorkDayController extends Controller
{
    public function __invoke(
        ListEmployeeWorkDaysRequest $request,
        string $uuid,
        ReadEmployeeWorkDays $workDays,
        JournalTelemetry $telemetry,
        ScopeGuard $scope,
        EmployeeScopeDirectory $employees,
    ): JsonResponse {
        $scope->ensureReaches(
            $scope->scopeOf($request->user()),
            $employees->departmentIdOf($uuid),
            'employee_workdays',
            $uuid,
        );

        $journal = $telemetry->measure(
            $uuid,
            static fn () => $workDays->handle($request->toQuery($uuid)),
        );

        return (new EmployeeWorkDaysResource($journal))->response();
    }
}
