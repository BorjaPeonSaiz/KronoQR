<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Infrastructure\Persistence\ShiftEntry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /api/v1/shift-entries/{uuid}` — rectificar las marcas de un tramo
 * vigente (RF-PA-04, RN-13).
 *
 * **Un campo ausente significa «no lo toques», nunca «vacialo».** Un `PATCH` que
 * solo trae la salida deja la entrada donde estaba, y no hay forma de retirar
 * una salida ya registrada: eso seria reabrir un tramo cerrado, que no es
 * ninguna de las cuatro acciones de RF-PA-04. Un tramo que no debio cerrarse se
 * anula y se vuelve a dar de alta, y asi consta lo que paso.
 *
 * **Hay que enviar al menos una de las dos marcas.** El comando de aplicacion lo
 * exige tambien, pero ahi seria un `500`; aqui es un `422` con el campo
 * señalado. Una peticion que solo trae el motivo no corrige nada, y una fila de
 * `shift_corrections` que dijera que se corrigio algo estaria mintiendo.
 */
final class CorrectShiftEntryRequest extends FormRequest
{
    use CorrectionReasonInput;
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }
    use UtcMarkInput;

    public function authorize(): bool
    {
        return Gate::allows('correct', ShiftEntry::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'clocked_in_at' => ['nullable', 'string', 'regex:'.self::UTC_PATTERN],
            'clocked_out_at' => ['nullable', 'string', 'regex:'.self::UTC_PATTERN],
            ...$this->reasonRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateExplanation($validator);
            $this->validateSomethingChanges($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->utcMarkMessages();
    }

    public function toCommand(string $shiftEntryUuid): CorrectShiftCommand
    {
        return new CorrectShiftCommand(
            shiftEntryUuid: $shiftEntryUuid,
            clockedInAt: $this->utcInstant('clocked_in_at'),
            clockedOutAt: $this->utcInstant('clocked_out_at'),
            reason: $this->correctionReason(),
            performedByUserId: $this->performedByUserId(),
        );
    }

    private function validateSomethingChanges(Validator $validator): void
    {
        if ($this->utcInstant('clocked_in_at') !== null || $this->utcInstant('clocked_out_at') !== null) {
            return;
        }

        $validator->errors()->add(
            'clocked_in_at',
            'Una correccion tiene que traer al menos una de las dos marcas: la de entrada o la de salida.',
        );
    }
}
