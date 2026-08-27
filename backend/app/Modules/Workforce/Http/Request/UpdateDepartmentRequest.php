<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\RenameDepartmentCommand;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Renombrado de departamento. `site_id` no se admite: mover un departamento de
 * centro arrastraria a sus empleados a otra zona horaria (RN-05).
 */
final class UpdateDepartmentRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('update', Department::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function toCommand(int $id): RenameDepartmentCommand
    {
        return new RenameDepartmentCommand(
            id: $id,
            name: $this->string('name')->trim()->value(),
        );
    }
}
