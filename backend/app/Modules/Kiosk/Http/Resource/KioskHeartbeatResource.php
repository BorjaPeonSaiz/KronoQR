<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `POST /api/v1/kiosk/heartbeat`: el esquema
 * `KioskHeartbeat`.
 *
 * **Un solo campo: la hora del servidor.** Con ella la tablet mide su propio
 * desfase de reloj y avisa (RF-AT-10), que es la mitad de cliente de esa
 * incidencia. Nunca le impide fichar: el desfase se registra escaneo a escaneo en
 * `scan_events.clock_skew_seconds` y se resuelve despues (regla dura 19).
 *
 * **No devuelve el estado del dispositivo**, ni su nombre, ni su centro, ni
 * cuando caduca su token. Un latido es una escritura, no una consulta, y cada
 * campo que devolviera seria informacion que un token robado obtiene sin pedirla.
 *
 * Los milisegundos se conservan por lo mismo que en `recorded_at`: redondear al
 * segundo perderia precision justo en el dato que sirve para diagnosticar un
 * reloj que se va.
 *
 * @property-read DateTimeImmutable $resource
 */
final class KioskHeartbeatResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DateTimeImmutable $serverTime */
        $serverTime = $this->resource;

        return ['server_time' => $serverTime->format('Y-m-d\TH:i:s.v\Z')];
    }
}
