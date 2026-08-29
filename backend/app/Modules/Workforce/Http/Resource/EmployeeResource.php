<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Employee` del contrato.
 *
 * **Envuelve el modelo de dominio y nunca el modelo Eloquent.** Es lo que hace
 * imposible que `national_id_hash` o `pin_hash` acaben en una respuesta el dia
 * que alguien anada un campo: no estan en el objeto que se serializa.
 *
 * **`pin_status` es el estado y nunca el PIN** (RF-ID-09). El panel necesita
 * saber a quien le falta recibirlo —esa es la mitad util de RF-ID-09— y para eso
 * no hace falta conocer ningun PIN. Llega como argumento y no se deduce de la
 * ficha porque el modelo de dominio no lo tiene: el PIN no es un atributo de la
 * persona, es una credencial con su propio ciclo.
 *
 * @property-read Employee $resource
 */
final class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Employee $employee, private readonly PinStatus $pinStatus)
    {
        parent::__construct($employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return [
            'uuid' => $employee->uuid,
            'employee_code' => $employee->code->value,
            'first_name' => $employee->firstName,
            'last_name' => $employee->lastName,
            // Siempre presente, aunque sea `null`: el contrato lo exige y un
            // campo que aparece y desaparece obliga al cliente a adivinar.
            'email' => $employee->email,
            'department_id' => $employee->departmentId,
            'status' => $employee->status->value,
            'hired_at' => $employee->hiredAt->format('Y-m-d'),
            'terminated_at' => $employee->terminatedAt?->format('Y-m-d'),
            'locale' => $employee->locale,
            'pin_status' => $this->pinStatus->value,
        ];
    }
}
