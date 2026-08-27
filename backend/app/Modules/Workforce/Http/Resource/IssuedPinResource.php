<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\UseCase\IssuedPin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `IssuedPin` del contrato (RF-ID-09).
 *
 * **Es el unico sitio de todo el producto que escribe un PIN en una respuesta**,
 * y solo lo hace con el objeto que acaba de generarlo: no lee de la base de
 * datos, porque de la base de datos no se puede leer un PIN.
 *
 * El estado que acompana es siempre `issued`: acabar de emitirlo y estar
 * entregado son incompatibles por definicion —emitir borra la entrega anterior—,
 * y decirlo aqui evita que el panel tenga que recargar la ficha para pintar el
 * indicador que ya sabe.
 *
 * @property-read IssuedPin $resource
 */
final class IssuedPinResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IssuedPin $issued */
        $issued = $this->resource;

        return [
            'employee_uuid' => $issued->employeeUuid,
            'pin' => $issued->pin,
            // Con milisegundos y sufijo Z: instante en UTC (regla dura 3).
            'issued_at' => $issued->issuedAt->format('Y-m-d\TH:i:s.v\Z'),
            'pin_status' => PinStatus::Issued->value,
        ];
    }
}
