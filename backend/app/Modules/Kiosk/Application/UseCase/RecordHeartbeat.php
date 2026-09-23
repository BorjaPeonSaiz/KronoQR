<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Command\RecordHeartbeatCommand;
use App\Modules\Kiosk\Application\Port\DeviceFleet;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Domain\ValueObject\ServiceCodeFingerprint;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Application\Port\KioskServiceCodeProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;

/**
 * Registra el latido de un quiosco (`POST /api/v1/kiosk/heartbeat`, RF-PA-07) y
 * acusa recibo de los errores que la tablet adjunta (RF-PD-15, tarea 5.12).
 *
 * ## Sin transaccion, y es correcto
 *
 * Escribe la telemetria de una fila y no publica ningun evento: no hay invariante
 * que proteger ni proyeccion que mantener. Abrir una transaccion aqui seria
 * ceremonia; lo que si hace falta —y lo hace el adaptador— es que la escritura sea
 * una sola sentencia. El historico de errores tampoco entra en ella: escribe por
 * su propia conexion precisamente para no depender del estado de otra (decision 6
 * de la ficha 5.12).
 *
 * ## Sin auditoria, y tambien es una decision
 *
 * Un latido no tiene relevancia legal: no toca el registro horario, no accede a
 * datos de terceros y no cambia ninguna autoridad. Auditarlo llenaria de ruido la
 * tabla que hay que enseñar en una inspeccion —un apunte por minuto y por
 * quiosco, cuatro años de retencion (RL-02)— y enterraria lo que si importa. La
 * traza operativa del latido son sus metricas, que es donde corresponde.
 *
 * ## El instante lo pone el servidor
 *
 * `last_seen_at` significa «cuando supe de el por ultima vez», no «que hora cree
 * la tablet que es». Sale del puerto `Clock` (regla dura 2): si viniera del
 * dispositivo, un reloj averiado dejaria un quiosco «visto» en 2031 y la alerta de
 * latido no volveria a saltar jamas.
 *
 * ## Los errores van DESPUES, y no pueden estropear el latido
 *
 * Primero se registra el latido y solo despues se vuelca lo que la tablet conto:
 * el orden importa porque lo que no puede perderse es la senal de que la tablet
 * sigue viva. {@see ErrorEventSink} promete por contrato **no lanzar nunca** y
 * devolver cuantos pudo guardar, asi que aqui no hay ni `try` ni rama de fallo
 * (regla dura 19): si el historico no responde, el latido responde `200` con
 * `client_errors_accepted: 0` y la tablet conserva su buffer para el siguiente.
 *
 * ## La huella del codigo de servicio sale de aqui (RF-KI-08, tarea 3.3)
 *
 * El latido es el unico canal autenticado que la tablet repite cada minuto, asi
 * que es por donde le llega —como huella, nunca en claro— el codigo con el que
 * se abre su pantalla de diagnostico. **Nunca puede tumbar un latido**: el
 * adaptador del puerto devuelve `null` si la configuracion no se puede leer, y
 * sin huella la pantalla se abre sin codigo (decision 7 de la ficha).
 *
 * ## Y desde la tarea 3.12, la ventana de actualizacion (RF-KI-07)
 *
 * `update_window` viaja por el mismo canal y por el mismo motivo. Aqui no se
 * decide nada con ella: el servidor no sabe si la tablet tiene una version
 * pendiente ni cuando fue su ultimo escaneo, asi que lo que hace es
 * **transportar** la configuracion del centro para que decida quien si tiene los
 * tres datos. Y es una ventana de permiso, no de bloqueo: fuera de ella el
 * quiosco sigue fichando y encolando (regla dura 19).
 *
 * ## Y desde la tarea 3.5, los dos ajustes de la pantalla de fichaje
 *
 * `break_clocking_enabled` (RF-AT-12) y `clock_skew_tolerance_seconds`
 * (RF-AT-10) viajan por el mismo canal y por el mismo motivo: es el unico
 * autenticado que la tablet repite cada minuto, y la tablet los guarda en local
 * para que el boton «Pausa» y el aviso de desfase sigan funcionando sin red. El
 * primero gobierna **la pantalla**, no al servidor: una intencion declarada se
 * honra siempre (decision 1 de la ficha 3.5). El segundo sustituye a la
 * constante de 15 minutos que el quiosco llevaba escrita, para que la tablet y
 * el servidor no puedan discrepar sobre cuando un reloj esta desviado.
 */
final readonly class RecordHeartbeat
{
    public function __construct(
        private DeviceFleet $devices,
        private KioskMetrics $metrics,
        private Clock $clock,
        private ErrorEventSink $errors,
        private KioskServiceCodeProvider $serviceCodes,
        private OperationalSettingsProvider $settings,
    ) {}

    public function handle(RecordHeartbeatCommand $command): HeartbeatOutcome
    {
        $seenAt = $this->clock->now();

        // Los dos ajustes que la tablet necesita para funcionar sin red
        // (RF-AT-12, RF-AT-10). Se leen aqui y no en el `Resource` porque la
        // capa Http no consulta configuracion; el adaptador resuelve la cascada
        // y **nunca falla por falta de fila**, asi que esto no puede tumbar un
        // latido (regla dura 19).
        $settings = $this->settings->forSite($command->siteId);

        $this->devices->recordHeartbeat($command->deviceId, $command->telemetry, $seenAt);

        $this->metrics->heartbeat(
            $command->deviceUuid,
            $seenAt->getTimestamp(),
            $command->telemetry->pendingQueueSize,
            $command->telemetry->batteryLevel,
        );

        return new HeartbeatOutcome(
            $seenAt,
            // Sin errores no se llama al historico: el caso normal de un latido es
            // que no haya pasado nada, y una llamada por minuto y por quiosco para
            // recorrer una lista vacia es trabajo que no compra nada.
            $command->clientErrors === [] ? 0 : $this->errors->recordAll($command->clientErrors),
            ServiceCodeFingerprint::of($command->deviceUuid, $this->serviceCodes->serviceCode()),
            $settings->breakClockingEnabled,
            // Minutos en el ajuste porque asi lo enuncia el negocio —«15 min»— y
            // segundos en el contrato porque es lo que la tablet compara con su
            // propio reloj. La conversion vive en un solo sitio.
            $settings->maximumClockSkewMinutes * 60,
            // RF-KI-07 (tarea 3.12). La ventana de actualizacion viaja tal cual:
            // aqui no se decide nada sobre ella —el servidor no sabe si la
            // tablet tiene una version pendiente ni cuando fue su ultimo
            // escaneo—, se transporta la configuracion del centro para que la
            // decision la tome quien tiene los tres datos.
            $settings->kioskUpdateWindow,
            $settings->kioskUpdateQuietMinutes,
        );
    }
}
