<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resource;

use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `POST /api/v1/incidents/{id}/resolve`: el esquema
 * `Incident` del contrato (RF-PA-05).
 *
 * Devuelve la incidencia **entera y ya cerrada** para que el panel sustituya la
 * fila sin volver a pedir la bandeja. La forma sale de {@see IncidentPayload},
 * que es la misma que usa el listado: dos serializaciones distintas del mismo
 * recurso acabarian discrepando justo en la pantalla que las mezcla.
 *
 * @property-read IncidentBoardRow $resource
 */
final class IncidentResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(IncidentBoardRow $row)
    {
        parent::__construct($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IncidentBoardRow $row */
        $row = $this->resource;

        return IncidentPayload::of($row);
    }
}
