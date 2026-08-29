<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CreateDepartmentCommand;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/departments` (contrato `CreateDepartmentRequest`).
 *
 * Sin `site_id`: el departamento queda en el centro de la instalacion
 * (ADR-040). `RejectsUnknownInput` lo rechaza si alguien lo envia.
 */
final class StoreDepartmentRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('create', Department::class);
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

    public function toCommand(): CreateDepartmentCommand
    {
        return new CreateDepartmentCommand(
            name: $this->string('name')->trim()->value(),
        );
    }
}
