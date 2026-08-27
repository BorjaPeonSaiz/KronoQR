<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\UseCase\RegisteredEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `EmployeeProvisioned` del contrato: el alta y el PIN
 * que la acompana (RF-ID-09, RF-GP-01).
 *
 * **Dos objetos y no uno.** `employee` es lo que se consulta, se lista y se
 * vuelve a pedir; `pin` es un secreto que existe en esta respuesta y en ninguna
 * otra. Fundirlos haria que el esquema de la ficha admitiera un PIN, que es la
 * puerta por la que un dia acabaria saliendo en un listado.
 *
 * @property-read RegisteredEmployee $resource
 */
final class EmployeeProvisionedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RegisteredEmployee $registered */
        $registered = $this->resource;

        return [
            // Recien emitido: el estado es `issued` por construccion, y por eso
            // no se consulta. Entregarlo es el acto siguiente y tiene su
            // endpoint.
            'employee' => (new EmployeeResource($registered->employee, PinStatus::Issued))->toArray($request),
            'pin' => (new IssuedPinResource($registered->pin))->toArray($request),
        ];
    }
}
