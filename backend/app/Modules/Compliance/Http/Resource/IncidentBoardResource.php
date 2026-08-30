<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resource;

use App\Modules\Compliance\Application\Port\IncidentBoardPage;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/incidents`: el esquema `IncidentCollection`
 * (RF-PA-05).
 *
 * ## Por que `meta` lleva la zona y el reloj del servidor
 *
 * La bandeja pinta la **antiguedad** de cada incidencia —«abierta hace tres
 * dias»— y esa cuenta se hace contra `generated_at`, no contra el reloj del
 * navegador: un portatil con la hora mal puesta escribiria «hace 3 horas» sobre
 * algo de anteayer, y quien lo lee decide con eso a que atiende primero. La zona
 * viaja por lo mismo que en el resto de la API (regla dura 3): el cliente no la
 * adivina ni usa la suya.
 *
 * **La zona es la del centro de la instalacion y hay exactamente uno** (ADR-040).
 * Aqui no se repite por fila, al contrario que en el detalle de jornada: alli un
 * tramo fichado en otro centro conserva su zona porque un traslado no reescribe
 * el pasado, y aqui no hay tramos de otro sitio.
 */
final class IncidentBoardResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        IncidentBoardPage $page,
        private readonly string $timeZone,
        private readonly DateTimeImmutable $generatedAt,
    ) {
        parent::__construct($page);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IncidentBoardPage $page */
        $page = $this->resource;

        return [
            'data' => array_map(
                static fn (IncidentBoardRow $row): array => IncidentPayload::of($row),
                $page->rows,
            ),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                // Acotado al alcance de quien pregunta (RF-ID-03): lo cuenta la
                // misma consulta que trae las filas.
                'total' => $page->total,
                'total_pages' => $page->totalPages(),
                'time_zone' => $this->timeZone,
                'generated_at' => IncidentPayload::utc($this->generatedAt),
            ],
        ];
    }
}
