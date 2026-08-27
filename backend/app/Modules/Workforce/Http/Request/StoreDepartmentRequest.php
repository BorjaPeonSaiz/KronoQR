<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CreateDepartmentCommand;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Alta de departamento.
 *
 * `manager_user_id` no se acepta: asignar responsable solo tiene efecto con el
 * ambito por departamento de RF-ID-03 (tarea 2.1), y aceptarlo antes seria
 * prometer un control de acceso que todavia no se aplica.
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
            'site_id' => ['required', 'integer', 'min:1', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function toCommand(): CreateDepartmentCommand
    {
        return new CreateDepartmentCommand(
            siteId: $this->integer('site_id'),
            name: $this->string('name')->trim()->value(),
        );
    }
}
