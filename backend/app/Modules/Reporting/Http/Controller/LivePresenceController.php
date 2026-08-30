<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadLivePresence;
use App\Modules\Reporting\Http\Request\ListLivePresenceRequest;
use App\Modules\Reporting\Http\Resource\LivePresenceResource;
use App\Modules\Reporting\Http\Support\RealtimeSubscription;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/attendance/live` — quien esta fichado ahora mismo (RF-PA-01,
 * RF-PA-02).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca la consulta y
 * serializa el `Resource`. **Ninguna decision vive aqui**: el alcance lo resuelve
 * `ScopeGuard` y lo aplica la consulta, el instante lo pone el puerto `Clock` y
 * la zona sale del centro de la instalacion.
 *
 * ## No hay `ScopeGuard::ensureReaches()` y no es un olvido
 *
 * Esto es un **listado**, y un listado se acota en la consulta y no devuelve
 * `403` (docblock de `ScopeGuard`): quien dirige tres departamentos ve a su gente
 * y no se entera de que existe mas. Un `403` aqui convertiria la pantalla de
 * presencia de un responsable en un error permanente, y filtrar despues de contar
 * daria unos recuentos que describen a personas que quien pregunta no puede ver.
 * El `403` con asiento se reserva para cuando se pide el recurso de una persona
 * concreta, que en este modulo es `GET /employees/{uuid}/workdays`.
 *
 * ## La constancia del acceso tampoco se escribe aqui
 *
 * La escribe la consulta, dentro (RS-05). Si dependiera de una linea de este
 * metodo, el dia que exista un segundo camino hacia la misma foto habria que
 * acordarse de repetirla.
 *
 * ## Solo lee, y no puede hacer otra cosa
 *
 * Ninguna respuesta de este endpoint cambia el registro: rectificarlo es `PATCH
 * /api/v1/shift-entries/{uuid}`, que vive en `Attendance` y exige
 * `attendance:correct`. Aqui solo se alcanza con `attendance:read`.
 */
final class LivePresenceController extends Controller
{
    public function __invoke(
        ListLivePresenceRequest $request,
        ReadLivePresence $presence,
        ScopeGuard $scope,
    ): JsonResponse {
        $query = $request->toQuery($scope);

        return (new LivePresenceResource(
            $presence->handle($query),
            // Los canales que puede pedir esta cuenta salen del MISMO alcance con
            // el que se resolvio la consulta: si se dedujeran por separado, el
            // panel podria acabar suscrito a algo que el listado no le enseña.
            RealtimeSubscription::forScope($query->scope),
        ))->response();
    }
}
