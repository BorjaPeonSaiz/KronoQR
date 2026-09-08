<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\Model\SupportGrant;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/support/grants`: el esquema
 * `SupportGrantCollection` (**RF-PD-11**).
 *
 * **Sin `meta` y sin paginacion.** Son las 100 mas recientes y ya: una
 * instalacion tiene decenas de concesiones en su vida, y una barra de paginas
 * sobre una lista que cabe en una pantalla es complejidad sin nadie que la use.
 * El historico completo —y el detalle de cada uso— esta en `audit_log`, que si
 * tiene su propia consulta.
 *
 * @property-read list<SupportGrant> $resource
 */
final class SupportGrantCollectionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  list<SupportGrant>  $resource
     */
    public function __construct(array $resource, private readonly DateTimeImmutable $asOf)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<SupportGrant> $grants */
        $grants = $this->resource;

        return [
            'data' => array_map(
                fn (SupportGrant $grant): array => (new SupportGrantResource($grant, $this->asOf))->toArray($request),
                $grants,
            ),
        ];
    }
}
