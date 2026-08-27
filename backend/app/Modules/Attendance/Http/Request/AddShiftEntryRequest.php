<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Infrastructure\Persistence\ShiftEntry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * `POST /api/v1/shift-entries` — alta manual de un tramo que nunca se ficho
 * (RF-PA-04, accion `created`).
 *
 * **`work_date` es obligatoria y no se deduce de `clocked_in_at`.** Es la unica
 * decision de esta peticion que no es evidente, y la razon esta en ADR-024: la
 * vuelta de una pausa a las 02:30 pertenece a la jornada que empezo ayer a las
 * 22:00. Derivarla la mandaria al dia siguiente y partiria el turno de noche por
 * la puerta de atras (RN-05, ADR-006, regla dura 4). Quien da de alta el tramo
 * esta mirando el detalle de una jornada concreta y sabe a cual lo añade.
 *
 * **`clocked_out_at` puede faltar** y eso da de alta el tramo abierto: es lo que
 * hace falta cuando la persona esta trabajando ahora mismo y no pudo fichar la
 * entrada. Que el resultado no deje dos turnos abiertos lo comprueba el agregado
 * (RN-01), no esta clase.
 */
final class AddShiftEntryRequest extends FormRequest
{
    use CorrectionReasonInput;
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }
    use UtcMarkInput;

    public function authorize(): bool
    {
        return Gate::allows('create', ShiftEntry::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            // El formato exige el sufijo `Z`, igual que el contrato: aceptar un
            // desplazamiento explicito convertiria la zona horaria en un dato
            // del cliente, y con turnos nocturnos eso es una jornada mal
            // atribuida (regla dura 3, RN-04).
            'clocked_in_at' => ['required', 'string', 'regex:'.self::UTC_PATTERN],
            'clocked_out_at' => ['nullable', 'string', 'regex:'.self::UTC_PATTERN],
            ...$this->reasonRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateExplanation($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->utcMarkMessages();
    }

    public function toCommand(): AddShiftEntryCommand
    {
        return new AddShiftEntryCommand(
            employeeUuid: $this->string('employee_uuid')->value(),
            workDate: $this->string('work_date')->value(),
            clockedInAt: $this->utcInstant('clocked_in_at')
                ?? throw new RuntimeException('clocked_in_at passed validation but did not arrive as a string.'),
            clockedOutAt: $this->utcInstant('clocked_out_at'),
            reason: $this->correctionReason(),
            performedByUserId: $this->performedByUserId(),
        );
    }
}
