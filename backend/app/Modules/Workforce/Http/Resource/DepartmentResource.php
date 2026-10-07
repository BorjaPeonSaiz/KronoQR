<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\DepartmentView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Department` del contrato.
 *
 * `manager_user_uuid` y `manager_name` salen aunque la cuenta este dada de
 * baja: es el panel quien lo interpreta y lo señala (RF-ID-10).
 *
 * @property-read DepartmentView $resource
 */
final class DepartmentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DepartmentView $view */
        $view = $this->resource;

        return [
            'id' => $view->department->id,
            'name' => $view->department->name,
            'manager_user_uuid' => $view->managerUserUuid,
            'manager_name' => $view->managerName,
        ];
    }
}
