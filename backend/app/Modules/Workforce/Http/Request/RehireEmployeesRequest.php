<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\RehireEmployeesCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Readmision en lote con una fecha de alta comun (RN-27, ADR-062 §6). Como
 * mucho tantas personas como filas admite la importacion
 * (`workforce.import.max_rows`), y sin repetir ninguna.
 */
final class RehireEmployeesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('rehire', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'employee_uuids' => ['required', 'array', 'list', 'min:1', 'max:'.max(1, config()->integer('workforce.import.max_rows'))],
            'employee_uuids.*' => ['required', 'uuid', 'distinct'],
            'hired_at' => ['required', 'date_format:Y-m-d'],
            'reset_pin' => ['sometimes', 'boolean'],
        ];
    }

    public function toCommand(): RehireEmployeesCommand
    {
        $uuids = [];

        foreach ((array) $this->input('employee_uuids') as $uuid) {
            $uuids[] = \is_string($uuid) ? $uuid : '';
        }

        $identifier = $this->user()?->getAuthIdentifier();

        return new RehireEmployeesCommand(
            uuids: $uuids,
            hiredAt: $this->string('hired_at')->value(),
            actorUserId: is_numeric($identifier) ? (int) $identifier : null,
            resetPin: $this->boolean('reset_pin'),
        );
    }
}
