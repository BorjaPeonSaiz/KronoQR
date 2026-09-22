<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\VoidAbsenceCommand;
use App\Modules\Workforce\Domain\Model\Absence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Anulacion de una ausencia (**RF-GP-04**).
 *
 * Un solo campo: el motivo. No hay nada que rectificar —eso es corregir— y quien
 * lo hace lo resuelve la sesion en curso.
 *
 * **El motivo es obligatorio**, con el mismo suelo y el mismo techo que el de la
 * correccion: anular devuelve esos dias al absentismo no justificado de alguien,
 * y «ok» no explica por que.
 */
final class VoidAbsenceRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('void', Absence::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:'.CorrectAbsenceRequest::MIN_REASON_LENGTH,
                'max:'.CorrectAbsenceRequest::MAX_REASON_LENGTH,
            ],
        ];
    }

    public function toCommand(string $absenceUuid): VoidAbsenceCommand
    {
        return new VoidAbsenceCommand(
            absenceUuid: $absenceUuid,
            reason: trim($this->string('reason')->value()),
            voidedByUserId: $this->managementUserId(),
        );
    }

    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
