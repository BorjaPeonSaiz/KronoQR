<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Http\Request\ListEmployeeWorkDaysRequest;
use App\Modules\Reporting\Http\Resource\EmployeeWorkDaysResource;
use App\Modules\Reporting\Http\Support\JournalTelemetry;
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
 */
final class EmployeeWorkDayController extends Controller
{
    public function __invoke(
        ListEmployeeWorkDaysRequest $request,
        string $uuid,
        ReadEmployeeWorkDays $workDays,
        JournalTelemetry $telemetry,
    ): JsonResponse {
        $journal = $telemetry->measure(
            $uuid,
            static fn () => $workDays->handle($request->toQuery($uuid)),
        );

        return (new EmployeeWorkDaysResource($journal))->response();
    }
}
