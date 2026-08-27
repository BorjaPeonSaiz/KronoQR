<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\UseCase\RegisterPinScanHandler;
use App\Modules\Attendance\Http\Request\PinScanRequest;
use App\Modules\Attendance\Http\Resource\ScanResource;
use App\Modules\Attendance\Http\Response\ScanRejectedResponse;
use App\Modules\Attendance\Http\Support\ScanTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/scan/pin` — fichaje de respaldo por PIN (RF-AT-11).
 *
 * **Es el mismo controlador que {@see ScanController} con otro caso de uso
 * delante**, y esa simetria es intencionada hasta en la respuesta: el `200` sale
 * por el mismo `ScanResource` y el `422` por el mismo `ScanRejectedResponse`. Un
 * cuerpo distinto —un campo `origin`, una marca de «esto fue por PIN»— le diria
 * al cliente por que via se ficho, y la pantalla que ve el empleado no tiene por
 * que ser distinta: ha fichado igual. Donde si consta la diferencia es donde
 * importa: en `scan_events.origin`, en `flagged_for_review` y en `audit_log`.
 *
 * ## El unico `if` de este controlador
 *
 * El mismo que el de `/scan`, y por la misma razon: ramifica entre `200` y `422`
 * y es la frontera de la regla dura 17. Por un lado sale lo que el quiosco
 * enseña; por el otro, **una respuesta unica y sin hueco donde alojar la
 * causa**. Aqui las causas posibles son tres —el sobre no abre, el PIN no es, el
 * empleado esta bloqueado— y ninguna de las tres asoma.
 *
 * ## Lo que este controlador NO hace
 *
 * **No abre el sobre ni compara ningun PIN**: eso es del caso de uso y del
 * verificador, que son quienes garantizan el tiempo constante. **No resuelve el
 * dispositivo desde el cuerpo**: sale del token autenticado, porque si viajara
 * en la peticion cualquier portador podria atribuir un fichaje a otro quiosco.
 * Y **no declara su ruta ni su limite de tasa**, que se declaran donde se
 * declaran los de sus hermanos.
 */
final class PinScanController extends Controller
{
    public function __invoke(
        PinScanRequest $request,
        RegisterPinScanHandler $handler,
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
