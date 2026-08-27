<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\PinDeliveryRecord;
use App\Modules\Workforce\Application\Port\PinStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `PinDeliveryReceipt` del contrato (RF-ID-09).
 *
 * **Sin PIN, y no por omision:** el objeto que envuelve no lo tiene. Es la
 * misma tecnica que usa {@see EmployeeResource} con `pin_hash` y el documento de
 * identidad — lo que no esta en el objeto no puede acabar en la respuesta el dia
 * que alguien anada un campo.
 *
 * @property-read PinDeliveryRecord $resource
 */
final class PinDeliveryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PinDeliveryRecord $delivery */
        $delivery = $this->resource;

        return [
            'employee_uuid' => $delivery->employeeUuid,
            'delivered_at' => $delivery->deliveredAt->format('Y-m-d\TH:i:s.v\Z'),
            'delivered_by' => $delivery->deliveredByUserUuid,
            'pin_status' => PinStatus::Delivered->value,
        ];
    }
}
