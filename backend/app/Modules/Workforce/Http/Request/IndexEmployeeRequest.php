<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Filtros y paginacion del listado de plantilla.
 *
 * **Rechaza lo desconocido en lugar de ignorarlo.** Un filtro mal escrito
 * —`?stauts=active`— devolveria la plantilla entera en silencio, y quien lo
 * escribio se iria convencido de haber filtrado.
 */
final class IndexEmployeeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'min:1', 'exists:sites,id'],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(EmploymentStatus::cases(), 'value'))],
            'page' => ['sometimes', 'integer', 'min:1'],
            // El techo de 100 es proteccion de recursos: una plantilla de 600
            // personas no se sirve entera en una respuesta.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function statusFilter(): ?EmploymentStatus
    {
        $status = $this->string('status')->value();

        return $status === '' ? null : EmploymentStatus::from($status);
    }

    public function siteFilter(): ?int
    {
        return $this->has('site_id') ? $this->integer('site_id') : null;
    }

    public function departmentFilter(): ?int
    {
        return $this->has('department_id') ? $this->integer('department_id') : null;
    }

    public function page(): int
    {
        return $this->has('page') ? $this->integer('page') : 1;
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : 25;
    }
}
