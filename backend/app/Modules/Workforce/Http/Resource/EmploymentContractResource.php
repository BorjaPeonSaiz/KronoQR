<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Domain\Model\EmploymentContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `EmploymentContract` del contrato (RF-GP-02).
 *
 * **Las horas salen como numero y no como texto**, al contrario que los totales
 * trabajados del informe: `weekly_hours` es una condicion pactada —37,5— y no
 * una duracion acumulada. La regla de «horas nunca como decimal» del
 * `/informe-nuevo` habla de lo segundo: nadie interpreta bien «has trabajado
 * 7,75», pero todo el mundo entiende «tienes un contrato de 37,5 horas», que es
 * ademas como esta escrito en el papel que firmo.
 *
 * **`is_current` se calcula del dominio y no se guarda**: es `valid_to IS NULL`,
 * y derivarlo aqui evita una columna que se podria quedar desincronizada.
 *
 * @property-read EmploymentContract $resource
 */
final class EmploymentContractResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmploymentContract $contract */
        $contract = $this->resource;

        return [
            'id' => $contract->id,
            'employee_uuid' => $contract->employeeUuid,
            'weekly_hours' => $contract->weeklyHours,
            'annual_hours' => $contract->annualHours,
            'schedule_type' => $contract->scheduleType->value,
            'valid_from' => $contract->isoValidFrom(),
            'valid_to' => $contract->isoValidTo(),
            'is_current' => $contract->isOpenEnded(),
        ];
    }
}
