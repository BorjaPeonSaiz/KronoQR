<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Domain\Model\SupportGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/support/grants/{uuid}/uses` (**RF-PD-11**, RL-15, RL-18).
 *
 * Solo `page` y `per_page`, y nada mas: un filtro por accion, por fechas o por
 * actor convertiria la ruta en una consulta de `audit_log`, y eso es
 * `audit:read`. `RejectsUnknownInput` devuelve `422` ante cualquier otro.
 */
final class IndexSupportGrantUsesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 25;

    public function authorize(): bool
    {
        return Gate::allows('viewUses', SupportGrant::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function page(): int
    {
        return $this->has('page') ? $this->integer('page') : 1;
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : self::DEFAULT_PER_PAGE;
    }
}
