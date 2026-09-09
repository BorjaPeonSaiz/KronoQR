<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\UseCase\ErrorHistoryView;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/diagnostics/errors`: el esquema
 * `ErrorEventCollection` (RF-PD-15).
 *
 * ## Por que `meta` lleva la zona y el reloj del servidor
 *
 * La pantalla pinta la **antiguedad** de cada grupo —«visto por ultima vez hace
 * tres horas»— y esa cuenta se hace contra `generated_at`, no contra el reloj
 * del navegador: un portatil con la hora mal puesta escribiria «hace 3 horas»
 * sobre algo de anteayer, y quien lo lee decide con eso a que atiende primero.
 * La zona viaja por lo mismo que en el resto de la API (regla dura 3): el
 * cliente no la adivina ni usa la suya.
 *
 * **La zona es la del centro y hay exactamente uno** (ADR-040). Antes de la
 * puesta en marcha no hay centro y sale `UTC`, que es lo que hay dentro y es la
 * verdad.
 *
 * ## Por que los dos recuentos de abiertos no dependen del filtro
 *
 * `open_errors` y `open_critical` cuentan **toda la instalacion**. Es la
 * cabecera de la pantalla y no puede cambiar segun el filtro que tenga puesto
 * quien mira: alguien filtrando por `source=console` leeria «0 criticos» con la
 * cola cayendose al lado. `total`, en cambio, es el de los filtros, porque es lo
 * que pagina.
 */
final class ErrorEventCollectionResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ErrorHistoryView $view, private readonly bool $withResolver = true)
    {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ErrorHistoryView $view */
        $view = $this->resource;
        $page = $view->page;

        return [
            'data' => array_map(
                fn (ErrorEvent $event): array => ErrorEventPayload::of($event, $this->withResolver),
                $page->rows,
            ),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'total_pages' => $page->totalPages(),
                'open_errors' => $page->openErrors,
                'open_critical' => $page->openCritical,
                'time_zone' => $view->timeZone,
                'generated_at' => ErrorEventPayload::utc($view->generatedAt),
            ],
        ];
    }
}
