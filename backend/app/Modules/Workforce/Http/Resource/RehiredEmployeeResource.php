<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\UseCase\IssuedPin;
use App\Modules\Workforce\Application\UseCase\RehiredEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `RehiredEmployee` (RN-27, ADR-062 §10): la ficha,
 * su periodo nuevo, la tarjeta nueva pendiente de imprimir y, solo con
 * `reset_pin`, el PIN que se acaba de emitir, que no vuelve a salir.
 *
 * `pin_status` llega ya resuelto: con PIN nuevo es `issued`, y sin el, el que
 * tuviera la persona.
 *
 * @property-read RehiredEmployee $resource
 */
final class RehiredEmployeeResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(RehiredEmployee $rehired, private readonly PinStatus $pinStatus)
    {
        parent::__construct($rehired);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RehiredEmployee $rehired */
        $rehired = $this->resource;

        return [
            'employee' => (new EmployeeResource($rehired->employee, $this->pinStatus))->toArray($request),
            'employment_period' => EmployeeResource::period($rehired->period),
            'credential' => [
                'uuid' => $rehired->credentialUuid,
                'status' => 'pending_print',
            ],
            ...($rehired->pin instanceof IssuedPin
                ? ['pin' => (new IssuedPinResource($rehired->pin))->toArray($request)]
                : []),
        ];
    }
}
