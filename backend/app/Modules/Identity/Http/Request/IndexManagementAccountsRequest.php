<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Port\ManagementAccountFilter;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/management-accounts` (RF-ID-10): filtros y paginacion, con las
 * mismas reglas que `GET /employees`.
 */
final class IndexManagementAccountsRequest extends FormRequest
{
    use RejectsUnknownInput;

    public const int MAX_SEARCH_LENGTH = 100;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', ManagementAccountView::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // `nullable`: un `?q=` vacio pegado de una busqueda anterior devuelve
            // la lista entera, no un `422`.
            'q' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_SEARCH_LENGTH],
            'status' => ['sometimes', 'string', 'in:active,deactivated'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_map(
                static fn (UserRole $role): string => $role->value,
                UserRole::managementRoles(),
            ))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filter(): ManagementAccountFilter
    {
        $search = trim($this->string('q')->value());
        $status = $this->string('status')->value();
        $role = $this->string('role')->value();

        return new ManagementAccountFilter(
            search: $search === '' ? null : $search,
            active: $status === '' ? null : $status === 'active',
            role: $role === '' ? null : UserRole::from($role),
        );
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
