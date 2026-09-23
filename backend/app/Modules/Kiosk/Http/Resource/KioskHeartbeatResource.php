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
 * **Seis campos, y los seis obligatorios.**
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
 * - `break_clocking_enabled` y `clock_skew_tolerance_seconds` son los dos
 *   ajustes que la pantalla de fichaje necesita para funcionar **sin red**
 *   (RF-AT-12, RF-AT-10, tarea 3.5): con el primero la tablet enseña u oculta el
 *   boton «Pausa», y con el segundo decide cuando avisar de que su reloj esta
 *   desviado — el mismo umbral con el que el servidor marca el escaneo, de modo
 *   que las dos pantallas no puedan contar historias distintas.
 * - `update_window` es la franja en la que la tablet **puede** aplicar una
 *   version nueva (RF-KI-07, tarea 3.12), en hora local del centro, mas los
 *   minutos de silencio que exige ademas. La declara el cliente en
 *   `KIOSK_UPDATE_WINDOW` y `KIOSK_UPDATE_QUIET_MINUTES`; el producto no infiere
 *   el cambio de turno (regla dura 13). Fuera de ella el quiosco sigue fichando
 *   y encolando: lo unico que no hace es recargarse.
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
            // **Los dos de la tarea 3.5, y los dos obligatorios** (RF-AT-12,
            // RF-AT-10). Van siempre por lo mismo que `client_errors_accepted`:
            // un campo ausente obligaria a la tablet a elegir un valor por su
            // cuenta, y en el caso del umbral de desfase eso es volver a la
            // constante de 15 minutos que esta tarea vino a retirar.
            'break_clocking_enabled' => $outcome->breakClockingEnabled,
            'clock_skew_tolerance_seconds' => $outcome->clockSkewToleranceSeconds,
            // **La ventana de actualizacion** (RF-KI-07, tarea 3.12), tambien
            // obligatoria y por lo mismo: sin ella la tablet tendria que elegir
            // una por su cuenta, y eso es volver a las tres franjas de cambio de
            // turno que esta tarea vino a retirar del codigo (regla dura 13).
            //
            // Los dos extremos por separado y no la cadena `HH:MM-HH:MM`: quien
            // lo consume compara horas, y obligar al cliente a partir una cadena
            // es repartir el parseo entre las tres SPA.
            'update_window' => [
                'start' => $outcome->updateWindow->start,
                'end' => $outcome->updateWindow->end,
                'quiet_minutes' => $outcome->updateQuietMinutes,
            ],
        ];
    }
}
