<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Http\Request\ListMyWorkDaysRequest;
use App\Modules\Reporting\Http\Resource\EmployeeWorkDaysResource;
use App\Modules\Reporting\Http\Support\JournalTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/workdays` — el registro horario **propio** (RF-ID-05, RL-05,
 * art. 34.9 ET).
 *
 * ## Reutiliza la consulta del panel entera, y esa es la decision
 *
 * El caso de uso, el objeto de valor, el `Resource` y la telemetria son
 * exactamente los de `GET /api/v1/employees/{uuid}/workdays`. Lo unico que
 * cambia es **quien puede pedirlo y sobre quien**: la policy y el origen del
 * `uuid`. Un segundo camino de lectura habria sido un segundo sitio donde
 * equivocarse con RN-06, con la regla dura 4 o con la conversion a hora local, y
 * habria dado al portal la posibilidad de enseñar un total distinto del que ve
 * RRHH sobre la misma jornada — que es exactamente la discusion que este
 * producto existe para no tener.
 *
 * ## El empleado sale del token, nunca de la URL
 *
 * No hay segmento `{uuid}` en esta ruta. Es la mitad de RF-ID-07 que no es un
 * ambito: sin identificador en la peticion, no hay URL que manipular para llegar
 * al registro de otra persona. Lo resuelve el `FormRequest`, despues de que la
 * policy haya comprobado que quien porta el token es una sesion de portal.
 *
 * ## Solo lee, y no puede hacer otra cosa
 *
 * El ambito de una sesion de portal es `self:read` y no existe ningun otro: no
 * hay ninguna ruta por la que un empleado pueda cambiar su propio registro
 * horario. Corregirlo es `PATCH /api/v1/shift-entries/{uuid}`, que exige
 * `attendance:correct` y una cuenta de gestion, y deja autor y motivo (RN-13).
 *
 * ## Y no queda asiento de auditoria
 *
 * RS-05 registra el acceso a datos personales **de terceros**, y aqui no lo hay.
 * La decision vive en el caso de uso, que la explica; el `FormRequest` solo la
 * declara.
 */
final class MyWorkDayController extends Controller
{
    public function __invoke(
        ListMyWorkDaysRequest $request,
        ReadEmployeeWorkDays $workDays,
        JournalTelemetry $telemetry,
    ): JsonResponse {
        $query = $request->toQuery();

        $journal = $telemetry->measure(
            $query->employeeUuid,
            static fn () => $workDays->handle($query),
        );

        return (new EmployeeWorkDaysResource($journal))->response();
    }
}
