<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Query\SiteView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Site` del contrato.
 *
 * `timezone_locked` dice si la zona ya esta fija porque existe registro horario
 * (RN-23, ADR-056): el panel no ofrece cambiarla. Al crear el centro, siempre
 * `false`.
 *
 * No expone `settings` ni `compliance_profile_id`: son de `Product`, y lo que un
 * modulo no gobierna tampoco lo publica.
 *
 * @property-read SiteView $resource
 */
final class SiteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteView $view */
        $view = $this->resource;
        $site = $view->site;

        return [
            'id' => $site->id,
            'name' => $site->name,
            'timezone' => $site->timezone->identifier,
            'timezone_locked' => $view->timezoneLocked(),
        ];
    }
}
