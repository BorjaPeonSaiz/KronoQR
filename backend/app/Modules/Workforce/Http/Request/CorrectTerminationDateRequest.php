<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CorrectTerminationDateCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Correccion de la fecha de cese de un periodo (RN-27, ADR-062 §7).
 *
 * El motivo es obligatorio, de 10 a 1 000 caracteres. **Aqui no se compara la
 * fecha con hoy ni con el periodo**: lo decide el dominio con la zona del centro
 * y la ficha bloqueada, y responde `422` en `terminated_at` igual que esta
 * validacion.
 */
final class CorrectTerminationDateRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('correctTerminationDate', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'terminated_at' => ['required', 'date_format:Y-m-d'],
            'reason' => [
                'required',
                'string',
                'min:'.TerminationDateCorrection::REASON_MIN_LENGTH,
                'max:'.TerminationDateCorrection::REASON_MAX_LENGTH,
            ],
        ];
    }

    public function toCommand(string $employeeUuid, string $periodUuid): CorrectTerminationDateCommand
    {
        $identifier = $this->user()?->getAuthIdentifier();

        if (! is_numeric($identifier)) {
            // La policy ya exige una cuenta de gestion: sin ella no hay autor
            // que dejar en la correccion (RN-13).
            throw new AccessDeniedHttpException;
        }

        return new CorrectTerminationDateCommand(
            employeeUuid: $employeeUuid,
            periodUuid: $periodUuid,
            terminatedAt: $this->string('terminated_at')->value(),
            reason: trim($this->string('reason')->value()),
            correctedByUserId: (int) $identifier,
        );
    }
}
