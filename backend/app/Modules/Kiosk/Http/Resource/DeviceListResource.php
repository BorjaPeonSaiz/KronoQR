<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\DeviceFleetView;
use App\Modules\Kiosk\Application\Query\DeviceView;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/devices`: el esquema `DeviceList`.
 *
 * **Se envuelve en un objeto y no es un array desnudo**, y desde la tarea 3.3
 * ese envoltorio se usa: lleva `meta`. Una instalacion es un hotel (ADR-040) con
 * unos pocos quioscos y la respuesta **no pagina** —paginar una lista que cabe
 * entera en la pantalla solo añadiria contrato que nadie usaria y que despues no
 * se puede quitar sin `v2` (ADR-012)—; lo que el envoltorio permitio es crecer
 * sin romper a quien ya la lee, que es justo lo que un array raiz no admite.
 *
 * ## Que lleva `meta`, y por que sin ello el panel mentiria
 *
 * - **`generated_at`** — el reloj del SERVIDOR, y el mismo instante con el que se
 *   juzgo cada fila. El panel mide la antiguedad de los latidos contra el,
 *   extrapolando el tiempo que lleve abierta la pantalla, y nunca contra el reloj
 *   del navegador: una estacion de trabajo con la hora desajustada pintaria media
 *   flota en rojo o toda en verde.
 * - **`timezone`** — la zona del centro, en la que el panel escribe los instantes
 *   (regla dura 3: los instantes viajan en UTC y se convierten al presentarlos).
 * - **`thresholds`** — los umbrales reales de esta instalacion, para que la
 *   leyenda diga la cifra que se esta aplicando y no una supuesta.
 *
 * @property-read DeviceFleetView $resource
 */
final class DeviceListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeviceFleetView $fleet */
        $fleet = $this->resource;

        return [
            'devices' => array_map(
                static fn (DeviceView $device): array => (new DeviceResource($device))->toArray($request),
                $fleet->devices,
            ),
            'meta' => [
                'generated_at' => $fleet->generatedAt
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z'),
                'timezone' => $fleet->timezone,
                // Los mismos umbrales que publica `kiosk:health --json`, escritos
                // una sola vez en el dominio: si la consola y el panel dijeran
                // cifras distintas, la leyenda de uno de los dos seria falsa.
                'thresholds' => $fleet->health->thresholdsAsArray(),
            ],
        ];
    }
}
