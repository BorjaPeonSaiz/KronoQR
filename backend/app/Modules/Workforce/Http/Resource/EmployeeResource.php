<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Workforce\Application\Port\EmploymentPeriodRecord;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\Port\TerminationCorrectionRecord;
use App\Modules\Workforce\Domain\Model\Employee;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

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

    /**
     * @param  list<EmploymentPeriodRecord>|null  $employmentPeriods  solo la ficha (`GET /employees/{uuid}`)
     *                                                                para `rrhh+`; `null` en las demas respuestas
     * @param  DateTimeImmutable|null  $offboardedAt  el instante de la baja efectiva
     *                                                (`EmployeeQueries::offboardedAtFor()`); obligatorio con
     *                                                la baja efectiva en el esquema de `rrhh+`
     */
    public function __construct(
        Employee $employee,
        private readonly PinStatus $pinStatus,
        private readonly bool $includesContactAndPin = true,
        private readonly ?array $employmentPeriods = null,
        private readonly ?DateTimeImmutable $offboardedAt = null,
    ) {
        parent::__construct($employee);
    }

    /**
     * Un periodo de alta, en la forma del esquema `EmploymentPeriod` (RN-27).
     *
     * @return array{uuid: string|null, hired_at: string, terminated_at: string|null, state: string}
     */
    public static function period(EmploymentPeriod $period): array
    {
        return [
            'uuid' => $period->uuid,
            'hired_at' => $period->hiredOn,
            'terminated_at' => $period->terminatedOn,
            'state' => $period->state(),
        ];
    }

    /**
     * Un periodo de la ficha de `rrhh+`, en la forma del esquema
     * `EmploymentPeriodRecord` (RN-27, RN-13): el periodo, el instante de su
     * baja efectiva y sus correcciones del cese, en orden cronologico.
     *
     * @return array<string, mixed>
     */
    public static function periodRecord(EmploymentPeriodRecord $record): array
    {
        return [
            ...self::period($record->period),
            'offboarded_at' => UtcInstant::format($record->offboardedAt),
            'corrections' => array_map(static fn (TerminationCorrectionRecord $correction): array => [
                'id' => $correction->id,
                'previous_terminated_at' => $correction->previousTerminatedOn,
                'terminated_at' => $correction->terminatedOn,
                'corrected_at' => UtcInstant::of($correction->correctedAt),
                // Esquema `TerminationCorrectionAuthor`, nunca el correo: la
                // cuenta puede estar minimizada (ADR-062 §7, ADR-060 §2) y
                // entonces no hay nombre.
                'corrected_by' => [
                    'uuid' => $correction->authorUuid,
                    'name' => $correction->authorName,
                    'minimised' => $correction->authorMinimised,
                ],
                // El motivo, que el asiento no lleva (regla dura 21). Tras la
                // minimizacion de la ficha es el texto fijo de ADR-060.
                'reason' => $correction->reason,
            ], $record->corrections),
        ];
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
            // `terminated_at` no digan ya. `offboarded_at` solo en el de
            // `rrhh+` (`EmployeeOffboarding`); el responsable recibe
            // `ScopedEmployeeOffboarding`, sin el.
            'offboarding' => $this->offboarding($employee, $full),
            // RN-27: los periodos de alta, solo en la ficha de `rrhh+`.
            ...($full && $this->employmentPeriods !== null
                ? ['employment_periods' => array_map(self::periodRecord(...), $this->employmentPeriods)]
                : []),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function offboarding(Employee $employee, bool $full): ?array
    {
        if ($employee->terminatedAt === null) {
            return null;
        }

        $scheduled = $employee->isOffboardingScheduled();

        $offboarding = [
            'state' => $scheduled ? 'scheduled' : 'effective',
            'terminated_at' => $employee->terminatedAt->format('Y-m-d'),
        ];

        if (! $full) {
            return $offboarding;
        }

        // Con la baja efectiva el instante existe siempre: `status = terminated`
        // si y solo si el periodo en curso tiene `offboarded_at`
        // (`employees_current_period_matches`). Si no llega, quien construye el
        // recurso se ha olvidado de leerlo, y un `null` aqui mentiria.
        if (! $scheduled && ! $this->offboardedAt instanceof DateTimeImmutable) {
            throw new LogicException('Falta el instante de la baja efectiva del empleado '.$employee->uuid.'.');
        }

        return [...$offboarding, 'offboarded_at' => $scheduled ? null : UtcInstant::format($this->offboardedAt)];
    }
}
