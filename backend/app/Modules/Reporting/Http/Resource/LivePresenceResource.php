<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Application\Support\PresenceEntryPayload;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Http\Support\RealtimeSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/attendance/live`: el esquema
 * `LivePresenceBoard` del contrato (RF-PA-01, RF-PA-02).
 *
 * ## Cada fila la escribe {@see PresenceEntryPayload} y no esta clase
 *
 * Porque la misma fila sale tambien por el WebSocket, y el contrato promete que
 * son la misma forma. Si se compusiera aqui, el difusor tendria su propia
 * version y el panel enseñaria filas distintas segun si el tiempo real estaba
 * vivo.
 *
 * ## Aqui no se convierte a hora local
 *
 * Y es la diferencia deliberada con `EmployeeWorkDaysResource`, que si manda
 * cada instante dos veces. El detalle de jornada **pinta horas** —«entro a las
 * 06:00»— y esa conversion la tiene que hacer el servidor con la zona del centro
 * (regla dura 3). La presencia pinta **duraciones** —«lleva 2 h 15 min
 * dentro»—, y una duracion no depende de ninguna zona: se calcula restando
 * `clocked_in_at` de `meta.generated_at`, los dos en UTC. La zona viaja igual, en
 * `meta.time_zone`, para la columna de hora de entrada que el panel decida
 * enseñar.
 *
 * ## Los recuentos no se recalculan aqui
 *
 * Salen de {@see PresenceBoard}, que los recibio de la consulta con el alcance ya
 * aplicado. Contar `data` en la serializacion daria otro numero —`data` esta
 * filtrado por situacion y los recuentos no— y el panel enseñaria una cifra que
 * no cuadra con sus propias pestañas.
 *
 * @property-read PresenceBoard $resource
 */
final class LivePresenceResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        PresenceBoard $board,
        private readonly RealtimeSubscription $realtime,
    ) {
        parent::__construct($board);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => array_map(
                static fn (PresenceEntry $entry): array => PresenceEntryPayload::of($entry),
                $this->resource->entries,
            ),
            'meta' => [
                // La referencia contra la que el panel calcula el tiempo
                // transcurrido, nunca el reloj del navegador (regla dura 3).
                'generated_at' => (string) PresenceEntryPayload::utc($this->resource->generatedAt),
                'time_zone' => $this->resource->timeZone,
                'present_count' => $this->resource->presentCount,
                'absent_count' => $this->resource->absentCount,
                'total' => $this->resource->total(),
                'realtime' => [
                    'enabled' => $this->realtime->enabled,
                    'key' => $this->realtime->key,
                    'path' => $this->realtime->path,
                    'auth_endpoint' => $this->realtime->authEndpoint,
                    'event' => $this->realtime->event,
                    'channels' => $this->realtime->channels,
                    'poll_interval_seconds' => $this->realtime->pollIntervalSeconds,
                    // Por que no hay tiempo real, cuando la causa es la licencia
                    // (ADR-019: la degradacion dice que, desde cuando y que
                    // hacer). `null` si lo hay, y tambien si lo que falta es
                    // configuracion de Reverb: eso lo arregla quien despliega.
                    'unavailable_reason' => $this->realtime->unavailableReason,
                    'unavailable_since' => $this->realtime->unavailableSince,
                ],
            ],
        ];
    }
}
