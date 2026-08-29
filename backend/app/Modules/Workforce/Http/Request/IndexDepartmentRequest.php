<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/departments`. Sin parametros: son los del centro de la
 * instalacion, que es el unico (ADR-040). `RejectsUnknownInput` sigue aqui
 * para que un `?site_id=` heredado de un enlace antiguo falle en voz alta en
 * vez de devolver la lista entera como si hubiera acotado algo.
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
        return [];
    }
}
