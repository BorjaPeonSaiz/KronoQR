<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use App\Modules\Compliance\Application\UseCase\IncidentBoardView;
use App\Modules\Compliance\Application\UseCase\ReadIncidentBoard;
use App\Modules\Compliance\Application\UseCase\ResolveIncident;
use App\Modules\Compliance\Http\Request\IndexIncidentRequest;
use App\Modules\Compliance\Http\Request\ResolveIncidentRequest;
use App\Modules\Compliance\Http\Resource\IncidentBoardResource;
use App\Modules\Compliance\Http\Resource\IncidentResource;
use App\Modules\Compliance\Http\Support\IncidentTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/incidents` y `POST /api/v1/incidents/{id}/resolve` — la bandeja
 * de incidencias y su flujo de resolucion (**RF-PA-05**).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca el caso de
 * uso y serializa el `Resource`. **Ninguna decision vive aqui**: el alcance lo
 * resuelve `ScopeGuard`, el instante lo pone el puerto `Clock`, la zona sale del
 * centro de la instalacion y si una incidencia se puede cerrar lo decide el
 * agregado.
 *
 * ## Dos formas de aplicar RF-ID-03, y la diferencia es deliberada
 *
 * - En el **listado**, filtrando en la consulta. No hay `403`: un responsable ve
 *   lo de su gente y no se entera de que existe mas. Un `403` al listar
 *   convertiria su bandeja en un error permanente, y filtrar despues de contar
 *   daria un `meta.total` que describe a personas que no puede ver.
 * - Al **resolver**, comprobando el recurso ya cargado y respondiendo `403` con
 *   asiento en `audit_log` (escenario «Aislamiento por departamento» del doc 01
 *   §11). Aqui si hay un sujeto identificable al que apuntar en el trail.
 *
 * La segunda ocurre **dentro del caso de uso** y no en este metodo, al contrario
 * que en la ficha de empleado: alli la comprobacion va entre dos lineas del
 * controlador porque no hay transaccion de por medio, y aqui va antes de abrir la
 * que escribe la resolucion y su asiento. Si dependiera de una linea de este
 * fichero, un segundo camino hacia la misma escritura —una consola, un comando—
 * podria olvidarla.
 *
 * ## `POST` para resolver y `GET` para mirar
 *
 * Resolver escribe: cambia el estado de una fila y deja asiento. Que no toque
 * ninguna hora del registro (RN-08) no lo convierte en una lectura. Lo contrario
 * pasa con el listado, que deja asiento de divulgacion (RS-05) y sigue siendo un
 * `GET`: dejar traza no es escribir.
 */
final class IncidentController extends Controller
{
    public function index(
        IndexIncidentRequest $request,
        ReadIncidentBoard $board,
        ScopeGuard $scope,
        IncidentTelemetry $telemetry,
    ): JsonResponse {
        $query = $request->toQuery($scope);

        $view = $telemetry->measureBoard(
            static fn (): IncidentBoardView => $board->handle($query),
        );

        return (new IncidentBoardResource($view->page, $view->timeZone, $view->generatedAt))->response();
    }

    public function resolve(
        ResolveIncidentRequest $request,
        int $id,
        ResolveIncident $resolve,
        ScopeGuard $scope,
        IncidentTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand($id, $scope);

        $row = $telemetry->measureResolution(
            static fn (): IncidentBoardRow => $resolve->handle($command),
        );

        return (new IncidentResource($row))->response();
    }
}
