<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/devices`: el esquema `DeviceList`.
 *
 * **Se envuelve en un objeto y no es un array desnudo**, y sin `meta`. Una
 * instalacion es un hotel (ADR-040) con unos pocos quioscos: paginar una lista que
 * cabe entera en la pantalla solo añadiria contrato que nadie usaria y que despues
 * no se puede quitar sin `v2` (ADR-012). El envoltorio es lo que deja crecer la
 * respuesta el dia que si haga falta, cosa que un array raiz no admite.
 *
 * @property-read list<DeviceSummary> $resource
 */
final class DeviceListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<DeviceSummary> $devices */
        $devices = $this->resource;

        return [
            'devices' => array_map(
                static fn (DeviceSummary $device): array => (new DeviceResource($device))->toArray($request),
                $devices,
            ),
        ];
    }
}
