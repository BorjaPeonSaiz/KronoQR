<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Command\RecordHeartbeatCommand;
use App\Modules\Kiosk\Application\Port\DeviceFleet;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ErrorEventSink;

/**
 * Registra el latido de un quiosco (`POST /api/v1/kiosk/heartbeat`, RF-PA-07) y
 * acusa recibo de los errores que la tablet adjunta (RF-PD-15, tarea 5.12).
 *
 * ## Sin transaccion, y es correcto
 *
 * Escribe tres columnas de una fila y no publica ningun evento: no hay invariante
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
 */
final readonly class RecordHeartbeat
{
    public function __construct(
        private DeviceFleet $devices,
        private KioskMetrics $metrics,
        private Clock $clock,
        private ErrorEventSink $errors,
    ) {}

    public function handle(RecordHeartbeatCommand $command): HeartbeatOutcome
    {
        $seenAt = $this->clock->now();

        $this->devices->recordHeartbeat(
            $command->deviceId,
            $command->appVersion,
            $command->pendingQueueSize,
            $seenAt,
        );

        $this->metrics->heartbeat(
            $command->deviceUuid,
            $seenAt->getTimestamp(),
            $command->pendingQueueSize,
        );

        return new HeartbeatOutcome(
            $seenAt,
            // Sin errores no se llama al historico: el caso normal de un latido es
            // que no haya pasado nada, y una llamada por minuto y por quiosco para
            // recorrer una lista vacia es trabajo que no compra nada.
            $command->clientErrors === [] ? 0 : $this->errors->recordAll($command->clientErrors),
        );
    }
}
