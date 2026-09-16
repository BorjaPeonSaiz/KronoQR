<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\UseCase\HeartbeatOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `POST /api/v1/kiosk/heartbeat`: el esquema
 * `KioskHeartbeat`.
 *
 * ## La huella del codigo de servicio, y solo la huella (RF-KI-08, tarea 3.3)
 *
 * `service_code_hash` es `sha256("{uuid del dispositivo}:{codigo}")`. **El codigo
 * nunca viaja en claro** y no puede: la tablet lo guarda en `localStorage` para
 * comprobar lo que alguien teclea sin salir a la red, que es lo que hace que la
 * pantalla de diagnostico funcione cuando no hay red — el momento en que hace
 * falta. Con el `uuid` dentro, la huella es distinta en cada quiosco: una tabla
 * precalculada no sirve para la tablet de al lado.
 *
 * **Tres campos, y los tres obligatorios.**
 *
 * - `server_time` es con lo que la tablet mide su propio desfase de reloj y avisa
 *   (RF-AT-10), que es la mitad de cliente de esa incidencia. Nunca le impide
 *   fichar: el desfase se registra escaneo a escaneo en
 *   `scan_events.clock_skew_seconds` y se resuelve despues (regla dura 19).
 * - `client_errors_accepted` es el acuse de los errores que la tablet adjunto
 *   (RF-PD-15, tarea 5.12). **Va siempre, tambien cuando vale `0`**: el contrato
 *   lo declara obligatorio porque un campo ausente y un cero significan cosas
 *   distintas para `acknowledge(n)`, y un cliente que tuviera que distinguirlos
 *   acabaria tirando errores que nadie guardo.
 *
 * **No devuelve el estado del dispositivo**, ni su nombre, ni su centro, ni
 * cuando caduca su token. Un latido es una escritura, no una consulta, y cada
 * campo que devolviera seria informacion que un token robado obtiene sin pedirla.
 *
 * Los milisegundos se conservan por lo mismo que en `recorded_at`: redondear al
 * segundo perderia precision justo en el dato que sirve para diagnosticar un
 * reloj que se va.
 *
 * @property-read HeartbeatOutcome $resource
 */
final class KioskHeartbeatResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HeartbeatOutcome $outcome */
        $outcome = $this->resource;

        return [
            'server_time' => $outcome->seenAt->format('Y-m-d\TH:i:s.v\Z'),
            'client_errors_accepted' => $outcome->clientErrorsAccepted,
            // **La huella, nunca el codigo** (RF-KI-08, tarea 3.3). Va siempre,
            // tambien cuando vale `null`: el contrato la declara obligatoria
            // porque un campo ausente y un `null` significan cosas distintas
            // para la tablet —«esta version del servidor no lo manda» frente a
            // «la instalacion no tiene codigo»— y del segundo depende que la
            // pantalla de diagnostico se abra sin pedirlo.
            'service_code_hash' => $outcome->serviceCodeHash,
        ];
    }
}
