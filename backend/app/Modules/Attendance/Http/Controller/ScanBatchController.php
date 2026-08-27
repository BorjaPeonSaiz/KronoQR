<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Http\Request\RegisterScanBatchRequest;
use App\Modules\Attendance\Http\Resource\ScanBatchResource;
use App\Modules\Attendance\Http\Support\ScanBatchTelemetry;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/scan/batch` — sincronizacion de la cola offline del quiosco
 * (RF-KI-04, doc 02 §6).
 *
 * Delgado igual que {@see ScanController}: valida —lo hace el `FormRequest`—,
 * construye el lote, invoca el caso de uso y devuelve el `Resource`. **Ni una
 * regla de negocio, y en particular ni una linea que ordene el lote**: eso lo
 * hace `ScanBatch` y por eso puede probarse sin base de datos.
 *
 * ## Por que no hay ningun `if` aqui
 *
 * Porque el codigo de la respuesta no depende de lo que haya pasado: **siempre
 * `207`**. Cada elemento lleva el suyo dentro. Es lo que convierte la regla dura
 * 19 en algo estructural en vez de en una promesa: no existe ninguna rama en la
 * que un elemento fallido pueda llevarse por delante el envio, porque no hay
 * ninguna rama.
 *
 * ## El lote no es una transaccion
 *
 * Cada escaneo abre la suya dentro de `RegisterScanHandler`, con su proyeccion y
 * su auditoria (RN-06, regla dura 6). Envolver los cincuenta en una sola habria
 * hecho que el rechazo de una tarjeta revocada revirtiera los fichajes ya
 * escritos de otras personas.
 */
final class ScanBatchController extends Controller
{
    public function __invoke(
        RegisterScanBatchRequest $request,
        RegisterScanBatchHandler $handler,
        ScanBatchTelemetry $telemetry,
    ): JsonResponse {
        $batch = $request->toBatch();

        $outcomes = $telemetry->measure(
            $batch,
            static fn (): array => $handler->handle($batch),
        );

        return (new ScanBatchResource($outcomes))
            ->response()
            ->setStatusCode(ScanBatchResource::STATUS);
    }
}
