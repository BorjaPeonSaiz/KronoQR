<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\UseCase\CompletedSetup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `SetupCompletion`: el estado final del asistente y el
 * resumen accionable (RF-PD-03, paso final).
 *
 * **Cifras y nada mas.** Ninguna persona, ningun nombre, ningun UUID (regla dura
 * 21). El detalle de a quien le falta la tarjeta esta en
 * `GET /api/v1/credentials/status`, que exige otro ambito y deja constancia de
 * la consulta (RF-QR-08, ADR-037).
 *
 * @property-read CompletedSetup $resource
 */
final class SetupCompletionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompletedSetup $completed */
        $completed = $this->resource;

        $summary = $completed->summary;

        return [
            'status' => (new SetupStatusResource($completed->state, detailed: true))->toArray($request),
            'summary' => [
                'employees' => $summary->employees,
                'departments' => $summary->departments,
                'credentials_pending' => $summary->credentialsPending,
                // El mismo enum que `GET /api/v1/license` y que la sonda de
                // salud: dos vocabularios para el estado de la licencia acaban
                // divergiendo el dia que uno gana un valor.
                'license' => $summary->license->value,
                'kiosks' => $summary->kiosks,
            ],
        ];
    }
}
