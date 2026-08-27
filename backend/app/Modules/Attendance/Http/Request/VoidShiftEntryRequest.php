<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\VoidShiftCommand;
use App\Modules\Attendance\Infrastructure\Persistence\ShiftEntry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/shift-entries/{uuid}/void` — declarar que un tramo no ocurrio
 * (RF-PA-04, ADR-026).
 *
 * **No lleva marcas**: no hay nada que rectificar. Solo hace falta saber cual,
 * quien y por que.
 *
 * **La policy que comprueba es `void` y no `correct`**, y esa es la diferencia
 * de esta peticion con el `PATCH`: anular quita horas del registro de una
 * persona y esta reservada a RRHH (plan 1.15, paso 6).
 */
final class VoidShiftEntryRequest extends FormRequest
{
    use CorrectionReasonInput;
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function authorize(): bool
    {
        return Gate::allows('void', ShiftEntry::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->reasonRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateExplanation($validator);
        });
    }

    public function toCommand(string $shiftEntryUuid): VoidShiftCommand
    {
        return new VoidShiftCommand(
            shiftEntryUuid: $shiftEntryUuid,
            reason: $this->correctionReason(),
            performedByUserId: $this->performedByUserId(),
        );
    }
}
