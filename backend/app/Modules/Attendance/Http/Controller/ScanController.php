<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Http\Request\RegisterScanRequest;
use App\Modules\Attendance\Http\Resource\ScanResource;
use App\Modules\Attendance\Http\Response\ScanRejectedResponse;
use App\Modules\Attendance\Http\Support\ScanTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/scan` — registro de un escaneo en el quiosco (RF-AT-01).
 *
 * Delgado a proposito: valida —lo hace el `FormRequest`—, construye el comando,
 * invoca el caso de uso y devuelve el `Resource`. **Ninguna regla de negocio
 * vive aqui.** Quien decide si el escaneo abre o cierra turno es el agregado
 * `WorkDay`; quien decide si cae en el periodo de gracia es `DebouncePolicy`.
 *
 * ## El unico `if` de este controlador
 *
 * Ramifica entre `200` y `422`, y es la frontera de la regla dura 17: por un
 * lado sale lo que el quiosco enseña al empleado, por el otro **una respuesta
 * unica y sin hueco donde alojar la causa**. Los dos desenlaces aceptados —el
 * fichaje y el anti-rebote— comparten el `200` porque los dos se procesaron
 * correctamente; devolver el anti-rebote como error dejaria la cola offline
 * reintentando contra una ventana que ya paso (ADR-031, RF-KI-04).
 *
 * ## El quiosco no espera a esta respuesta
 *
 * Regla dura 19 y doc 02 §6: la tablet confirma al empleado en local en menos de
 * 300 ms y encola; esta llamada **sincroniza**. Un fallo de red no puede impedir
 * fichar, y por eso este endpoint puede permitirse ser el que dice la verdad
 * definitiva sin ser el que desbloquea a la persona.
 *
 * ## Lo que este controlador NO hace
 *
 * **No declara su ruta ni su rate limiting**: eso es la tarea 1.7. Y **no
 * resuelve el dispositivo desde el cuerpo de la peticion**: sale del token
 * autenticado (ver `ScanningDevice`), porque si viajara en el cuerpo cualquier
 * portador podria atribuir un fichaje a otro quiosco.
 */
final class ScanController extends Controller
{
    public function __invoke(
        RegisterScanRequest $request,
        RegisterScanHandler $handler,
        ScanTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand();

        $result = $telemetry->measure(
            $command->scanId,
            $command->deviceUuid,
            static fn () => $handler->handle($command),
        );

        if ($result->isRejected()) {
            return ScanRejectedResponse::for($result->scanId);
        }

        return (new ScanResource($result))->response();
    }
}
