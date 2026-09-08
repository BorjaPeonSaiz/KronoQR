<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\Model\DataExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/data-export`: el esquema
 * `DataExportCollection` (**RF-PD-14**).
 *
 * **Sin `meta` y sin paginacion.** Son las 20 mas recientes y ya; el esquema lo
 * declara con `maxItems: 20`. Una instalacion pide una exportacion completa unas
 * cuantas veces al año, asi que una barra de paginas sobre una lista que cabe en
 * media pantalla seria complejidad sin nadie que la use. El historico completo
 * esta en `audit_log`.
 *
 * @property-read list<DataExport> $resource
 */
final class DataExportCollectionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  list<DataExport>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<DataExport> $exports */
        $exports = $this->resource;

        return [
            'data' => array_map(DataExportResource::payload(...), $exports),
        ];
    }
}
