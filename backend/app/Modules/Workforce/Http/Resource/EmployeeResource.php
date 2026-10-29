<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion de los esquemas `Employee` y `ScopedEmployee` del contrato.
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
 * ## `email`, `locale` y `pin_status` se OMITEN para el responsable (DIV-1)
 *
 * Con `$includesContactAndPin` a `false` el objeto es un `ScopedEmployee`: las
 * tres claves **no existen**. `null` en `email` diria «no tiene correo», y lo
 * cierto es «no te corresponde». La decision llega ya resuelta por
 * `EmployeePolicy::viewContactAndPin`, como la nota de `AbsenceResource`.
 *
 * @property-read Employee $resource
 */
final class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Employee $employee,
        private readonly PinStatus $pinStatus,
        private readonly bool $includesContactAndPin = true,
    ) {
        parent::__construct($employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        $full = $this->includesContactAndPin;

        // Mismo orden de claves que el contrato. Las tres omitidas para el
        // responsable NO EXISTEN en su objeto (ver el docblock).
        return [
            'uuid' => $employee->uuid,
            'employee_code' => $employee->code->value,
            'first_name' => $employee->firstName,
            'last_name' => $employee->lastName,
            // Siempre presente para `rrhh+`, aunque sea `null`: el contrato lo
            // exige y un campo que aparece y desaparece en el mismo esquema
            // obliga al cliente a adivinar.
            ...($full ? ['email' => $employee->email] : []),
            'department_id' => $employee->departmentId,
            'status' => $employee->status->value,
            'hired_at' => $employee->hiredAt->format('Y-m-d'),
            'terminated_at' => $employee->terminatedAt?->format('Y-m-d'),
            ...($full ? ['locale' => $employee->locale] : []),
            'teleworking' => $employee->teleworking,
            ...($full ? ['pin_status' => $this->pinStatus->value] : []),
            // ADR-060: cuando se minimizo la ficha, en UTC; `null` si se conserva
            // entera. Es lo que dice que la ficha esta minimizada, no los nombres
            // vacios. Lo reciben los dos esquemas, tambien el del responsable.
            'anonymized_at' => UtcInstant::format($employee->anonymizedAt),
            // RN-14, ADR-062 §3: la baja, derivada del par estado–fecha. La
            // reciben los dos esquemas: no dice nada que `status` y
            // `terminated_at` no digan ya.
            'offboarding' => self::offboarding($employee),
        ];
    }

    /**
     * @return array{state: string, terminated_at: string}|null
     */
    private static function offboarding(Employee $employee): ?array
    {
        if ($employee->terminatedAt === null) {
            return null;
        }

        return [
            'state' => $employee->isOffboardingScheduled() ? 'scheduled' : 'effective',
            'terminated_at' => $employee->terminatedAt->format('Y-m-d'),
        ];
    }
}
