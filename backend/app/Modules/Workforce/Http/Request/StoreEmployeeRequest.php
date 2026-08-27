<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Alta de empleado (RF-GP-01).
 *
 * **`email` es `nullable` y no aparece en ninguna regla `required`** (regla dura
 * 12, ADR-015). Es el punto exacto donde el producto podria empezar a depender
 * del correo del empleado sin que nadie lo notara, y no lo hace: el escenario
 * «alta de Youssef sin direccion de correo» tiene su propia prueba para que
 * siga siendo asi.
 *
 * **`employee_code` no se acepta.** Lo genera el servidor; si llegara del
 * cliente, la opacidad del codigo dependeria de lo que teclee quien rellena el
 * formulario.
 */
final class StoreEmployeeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('create', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'min:1', 'exists:sites,id'],
            'department_id' => ['nullable', 'integer', 'min:1', 'exists:departments,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:150'],
            // Opcional de verdad: `nullable`, no `required_without` de nada.
            'email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            // El documento no se almacena: se hashea con pgcrypto (RL-08).
            'national_id' => ['nullable', 'string', 'min:4', 'max:32'],
            'hired_at' => ['required', 'date_format:Y-m-d'],
            'locale' => ['sometimes', 'string', 'min:2', 'max:10'],
        ];
    }

    public function toCommand(): RegisterEmployeeCommand
    {
        return new RegisterEmployeeCommand(
            siteId: $this->integer('site_id'),
            departmentId: $this->input('department_id') === null ? null : $this->integer('department_id'),
            firstName: $this->string('first_name')->trim()->value(),
            lastName: $this->string('last_name')->trim()->value(),
            email: $this->optionalString('email'),
            nationalId: $this->optionalString('national_id'),
            hiredAt: $this->string('hired_at')->value(),
            locale: $this->string('locale', 'es')->value(),
        );
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
