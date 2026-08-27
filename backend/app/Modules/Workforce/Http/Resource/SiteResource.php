<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Domain\Model\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Site` del contrato.
 *
 * No expone `settings` ni `compliance_profile_id`: son de `Product`, y lo que un
 * modulo no gobierna tampoco lo publica.
 *
 * @property-read Site $resource
 */
final class SiteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Site $site */
        $site = $this->resource;

        return [
            'id' => $site->id,
            'name' => $site->name,
            'timezone' => $site->timezone->identifier,
        ];
    }
}
