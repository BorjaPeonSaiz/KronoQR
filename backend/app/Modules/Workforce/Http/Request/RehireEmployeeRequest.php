<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Http\Rules\ActiveLocale;
use App\Modules\Workforce\Application\Command\RehireContractTerms;
use App\Modules\Workforce\Application\Command\RehireEmployeeCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Readmision de una persona de baja (RN-27, ADR-062 §6). Un solo dato
 * obligatorio, `hired_at`; lo demas se toma de la ficha si no viene.
 *
 * **Aqui no se compara `hired_at` con el cese**: lo decide el dominio
 * (`Employee::rehire()`) con la ficha bloqueada, y responde `422` en `hired_at`
 * igual que esta validacion. Tampoco se compara con «hoy»: la fecha de alta
 * tiene los mismos limites que la del alta inicial, que no los tiene.
 */
final class RehireEmployeeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('rehire', Employee::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'hired_at' => ['required', 'date_format:Y-m-d'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:departments,id'],
            'locale' => ['sometimes', 'string', ActiveLocale::rule()],
            'contract' => ['sometimes', 'nullable', 'array:weekly_hours,annual_hours,schedule_type'],
            'contract.weekly_hours' => ['required_with:contract', 'numeric', 'gt:0', 'max:'.StoreEmploymentContractRequest::MAX_WEEKLY_HOURS],
            'contract.annual_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:'.StoreEmploymentContractRequest::MAX_ANNUAL_HOURS],
            'contract.schedule_type' => ['required_with:contract', 'string', 'in:'.implode(',', ScheduleType::names())],
            'reset_pin' => ['sometimes', 'boolean'],
        ];
    }

    public function toCommand(string $uuid): RehireEmployeeCommand
    {
        $departmentId = $this->input('department_id');

        return new RehireEmployeeCommand(
            uuid: $uuid,
            hiredAt: $this->string('hired_at')->value(),
            actorUserId: $this->managementUserId(),
            departmentId: is_numeric($departmentId) ? (int) $departmentId : null,
            departmentGiven: $this->has('department_id'),
            locale: $this->has('locale') ? ActiveLocale::canonical($this->string('locale')->value()) : null,
            contract: $this->contractTerms(),
            contractGiven: $this->has('contract'),
            resetPin: $this->boolean('reset_pin'),
        );
    }

    private function contractTerms(): ?RehireContractTerms
    {
        $weekly = $this->input('contract.weekly_hours');
        $annual = $this->input('contract.annual_hours');
        $schedule = $this->input('contract.schedule_type');

        if (! is_numeric($weekly) || ! \is_string($schedule)) {
            return null;
        }

        return new RehireContractTerms(
            weeklyHours: (float) $weekly,
            annualHours: is_numeric($annual) ? (float) $annual : null,
            scheduleType: ScheduleType::from($schedule),
        );
    }

    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
