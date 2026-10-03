<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\UseCase\ReportDiscardedScans;
use App\Modules\Attendance\Http\Request\ReportDiscardedScansRequest;
use App\Modules\Attendance\Http\Resource\DiscardedScanReceiptResource;
use App\Modules\Attendance\Http\Support\DiscardedScanTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/scan/discarded` — el aviso de los fichajes que el quiosco saco
 * de su cola porque el servidor declaro invalida la peticion (RN-22, ADR-047).
 *
 * Delgado como {@see ScanBatchController}: valida —lo hace el `FormRequest`, que
 * tambien autoriza con `ScanPolicy`—, invoca el caso de uso y devuelve el acuse.
 * **La respuesta es la misma se atribuya o no** cada aviso, y no dice si abrira
 * incidencia: lleva todos los `scan_id` recibidos, que son los que el quiosco ya
 * puede olvidar.
 */
final class DiscardedScanController extends Controller
{
    public function __invoke(
        ReportDiscardedScansRequest $request,
        ReportDiscardedScans $handler,
        DiscardedScanTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand();

        $acknowledged = $telemetry->measure($command, static fn (): array => $handler->handle($command));

        return (new DiscardedScanReceiptResource($acknowledged))->response();
    }
}
