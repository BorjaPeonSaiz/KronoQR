<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Listado de departamentos, opcionalmente de un solo centro.
 */
final class IndexDepartmentRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Department::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'min:1', 'exists:sites,id'],
        ];
    }

    public function siteFilter(): ?int
    {
        return $this->has('site_id') ? $this->integer('site_id') : null;
    }
}
