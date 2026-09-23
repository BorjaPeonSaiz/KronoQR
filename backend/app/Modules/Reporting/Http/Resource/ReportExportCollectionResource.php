<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Domain\Model\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Las exportaciones recientes del solicitante (**RF-IN-06**).
 *
 * ## Sin `download` en ninguna
 *
 * La lista **no acuña enlaces**. Si lo hiciera, una sola peticion de sondeo
 * —que la pantalla repite cada diez segundos mientras haya algo en curso—
 * acuñaria veinte tokens vivos quince minutos cada uno, y el que estuviera
 * usando alguien quedaria invalidado por el sondeo siguiente. El enlace lo emite
 * `GET /reports/exports/{uuid}`, que es un acto deliberado (ADR-041).
 *
 * ## Sin paginacion y sin `meta`
 *
 * Veinte filas, las mas recientes, de la mas nueva a la mas antigua. Una barra de
 * paginas sobre una lista que cabe en media pantalla es complejidad sin nadie que
 * la use, y un `meta.total` obligaria a una segunda consulta para un numero que
 * no cambia ninguna decision. El historico completo esta en `audit_log`.
 */
final class ReportExportCollectionResource extends JsonResource
{
    /** El envoltorio `data` se escribe a mano en {@see self::toArray()}, como en el resto del producto. */
    public static $wrap = null;

    /**
     * @param  list<ReportExport>  $exports
     */
    public function __construct(array $exports)
    {
        parent::__construct($exports);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<ReportExport> $exports */
        $exports = $this->resource;

        return [
            'data' => array_map(
                static fn (ReportExport $export): array => ReportExportResource::payload($export),
                $exports,
            ),
        ];
    }
}
