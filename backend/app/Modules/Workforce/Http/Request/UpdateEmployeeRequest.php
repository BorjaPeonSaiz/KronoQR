<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /api/v1/employees/{uuid}` (contrato `UpdateEmployeeRequest`).
 *
 * Sin `site_id` (ADR-040) y sin `terminated`: la baja tiene su propio endpoint.
 */
final class UpdateEmployeeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('update', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:departments,id'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:190'],
            'status' => ['sometimes', 'string', 'in:'.EmploymentStatus::ACTIVE->value.','.EmploymentStatus::SUSPENDED->value],
            'locale' => ['sometimes', 'string', 'min:2', 'max:10'],
        ];
    }

    public function toCommand(string $uuid): UpdateEmployeeCommand
    {
        return new UpdateEmployeeCommand(
            uuid: $uuid,
            firstName: $this->filledString('first_name'),
            lastName: $this->filledString('last_name'),
            email: $this->nullableString('email'),
            emailGiven: $this->has('email'),
            departmentId: $this->has('department_id') && $this->input('department_id') !== null
                ? $this->integer('department_id')
                : null,
            departmentGiven: $this->has('department_id'),
            status: $this->filledString('status'),
            locale: $this->filledString('locale'),
        );
    }

    private function filledString(string $key): ?string
    {
        $value = $this->input($key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
