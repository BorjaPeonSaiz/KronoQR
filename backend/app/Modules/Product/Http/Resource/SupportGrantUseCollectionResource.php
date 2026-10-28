<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\Port\SupportGrantUse;
use App\Modules\Product\Application\Port\SupportGrantUsePage;
use App\Modules\Shared\Domain\Support\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `SupportGrantUseCollection` del contrato (**RF-PD-11**, ADR-020 §3): las
 * ventanas de uso de una concesion, con `PageMeta`.
 *
 * Tres campos por linea y ninguno mas: el instante en UTC y el metodo y el
 * patron de ruta de la peticion que abrio la ventana.
 *
 * @property SupportGrantUsePage $resource
 */
final class SupportGrantUseCollectionResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(SupportGrantUsePage $page)
    {
        parent::__construct($page);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;

        return [
            'data' => array_map(
                static fn (SupportGrantUse $use): array => [
                    'started_at' => UtcInstant::of($use->startedAt),
                    'method' => $use->method,
                    'route' => $use->route,
                ],
                $page->rows,
            ),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'total_pages' => $page->totalPages(),
            ],
        ];
    }
}
