<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resource;

use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use App\Modules\Attendance\Application\UseCase\ScanBatchOutcome;
use App\Modules\Attendance\Http\Response\ScanNotProcessedResponse;
use App\Modules\Attendance\Http\Response\ScanRejectedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `207 Multi-Status` de `POST /api/v1/scan/batch`: el esquema
 * `ScanBatchResponse`.
 *
 * **Cada entrada lleva el codigo que habria devuelto el endpoint individual**, y
 * ese numero es lo unico que el quiosco necesita para saber que hacer con su cola:
 * `200` y `422` sacan el elemento, `503` lo conserva. Es deliberado que sea un
 * numero HTTP y no un enum propio: el quiosco ya sabe interpretar codigos de
 * estado, y un vocabulario nuevo solo para el lote seria una segunda forma de
 * decir lo mismo.
 *
 * **El cuerpo de cada entrada no se construye aqui.** El aceptado lo serializa
 * {@see ScanResource}, el rechazo lo da {@see ScanRejectedResponse::body()} y el
 * no procesado {@see ScanNotProcessedResponse::body()}. Es lo que sostiene la
 * regla dura 17 en este endpoint: si el lote compusiera su propio rechazo, la
 * promesa de que existe **una sola** respuesta de rechazo dejaria de ser
 * comprobable, y una fuga en el lote es igual de util para un atacante que una
 * fuga en el endpoint suelto.
 *
 * @property-read list<ScanBatchOutcome> $resource
 */
final class ScanBatchResource extends JsonResource
{
    public static $wrap = null;

    /**
     * `207` y no `200`, y tampoco `multi-status` calculado segun lo que haya
     * pasado: el codigo describe **la forma de la respuesta** —un resultado por
     * elemento—, no el desenlace agregado, que no existe. Un lote en el que todo
     * se acepto y otro en el que todo se rechazo tienen la misma forma y el mismo
     * codigo, y eso es lo que impide que el cliente ramifique por el codigo de la
     * peticion en lugar de por el de cada elemento.
     */
    public const int STATUS = JsonResponse::HTTP_MULTI_STATUS;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<ScanBatchOutcome> $outcomes */
        $outcomes = $this->resource;

        $results = [];

        foreach ($outcomes as $outcome) {
            $results[] = $this->entry($outcome, $request);
        }

        return ['results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(ScanBatchOutcome $outcome, Request $request): array
    {
        $result = $outcome->result;

        if (! $result instanceof RegisterScanResult) {
            return [
                'scan_id' => $outcome->scanId,
                'status' => JsonResponse::HTTP_SERVICE_UNAVAILABLE,
                'outcome' => ScanNotProcessedResponse::body($outcome->scanId),
            ];
        }

        if ($result->isRejected()) {
            return [
                'scan_id' => $outcome->scanId,
                'status' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'outcome' => ScanRejectedResponse::body($outcome->scanId),
            ];
        }

        // El anti-rebote entra por aqui, con `200`, porque es un desenlace
        // aceptado (ADR-031): un `4xx` dejaria a la cola offline reintentando
        // contra una ventana de gracia que ya paso.
        return [
            'scan_id' => $outcome->scanId,
            'status' => JsonResponse::HTTP_OK,
            'outcome' => (new ScanResource($result))->toArray($request),
        ];
    }
}
