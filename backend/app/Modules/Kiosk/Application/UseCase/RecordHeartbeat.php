<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Command\RecordHeartbeatCommand;
use App\Modules\Kiosk\Application\Port\DeviceFleet;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;

/**
 * Registra el latido de un quiosco (`POST /api/v1/kiosk/heartbeat`, RF-PA-07).
 *
 * ## Sin transaccion, y es correcto
 *
 * Escribe tres columnas de una fila y no publica ningun evento: no hay invariante
 * que proteger ni proyeccion que mantener. Abrir una transaccion aqui seria
 * ceremonia; lo que si hace falta —y lo hace el adaptador— es que la escritura sea
 * una sola sentencia.
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
 */
final readonly class RecordHeartbeat
{
    public function __construct(
        private DeviceFleet $devices,
        private KioskMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * @return DateTimeImmutable La hora del servidor, que es lo unico que se le
     *                           devuelve al quiosco: con ella mide su propio
     *                           desfase de reloj y avisa (RF-AT-10).
     */
    public function handle(RecordHeartbeatCommand $command): DateTimeImmutable
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

        return $seenAt;
    }
}
