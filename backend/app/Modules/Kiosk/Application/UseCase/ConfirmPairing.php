<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Command\ConfirmPairingCommand;
use App\Modules\Kiosk\Application\Exception\PairingRaceLost;
use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Application\Port\KioskEventPublisher;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Application\Port\PairingRequests;
use App\Modules\Kiosk\Application\Port\PairingSecrets;
use App\Modules\Kiosk\Application\Query\PairingConfirmation;
use App\Modules\Kiosk\Domain\Event\DeviceProvisioned;
use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Paso 2 de RF-PD-06: un `admin` teclea el codigo y el quiosco queda dado de
 * alta.
 *
 * ## Es el unico acto con actor de los tres
 *
 * La tablet pide y recoge de forma anonima —no tiene sesion, no puede tenerla— y
 * la unica persona que decide algo es quien teclea aqui. Por eso el asiento de
 * `audit_log` cuelga de este paso y no del `claim`: si colgara del `claim`, el
 * alta de un quiosco quedaria firmada por `system`, es decir, por nadie.
 *
 * ## Una transaccion, y todo dentro
 *
 * El alta o la reactivacion de la fila de `devices`, el cambio de estado de la
 * solicitud y la publicacion del evento —que `Compliance` sella de forma
 * sincrona— van juntos. Si el asiento falla, el quiosco **no queda dado de alta**
 * (regla dura 6, ADR-027): un origen de fichajes creado sin traza es peor que un
 * alta que no llega a producirse, porque la segunda se repite y la primera no se
 * descubre.
 *
 * ## Quien arbitra cuando dos personas teclean el mismo codigo
 *
 * PostgreSQL, por dos vias distintas y las dos hacen falta:
 *
 * - **Mismo codigo**: `markConfirmed()` escribe con `WHERE status = 'pending'`.
 *   Si afecta a cero filas, otra peticion se adelanto y este camino degrada a
 *   rechazo aunque el agregado hubiera dicho que si.
 * - **Mismo nombre**: el UNIQUE `devices_site_id_name_unique`. Dos confirmaciones
 *   con nombres nuevos e iguales llegan las dos a `provision()`, y la que pierde
 *   recibe un `422` sobre `name` en lugar de un `500`.
 *
 * El orden importa: primero se provisiona la fila de `devices` y despues se marca
 * la solicitud. Al reves, el ganador del `UPDATE` podria quedarse sin dispositivo
 * si el alta fallara. Como el perdedor deshace su transaccion entera, la fila que
 * llego a crear no sobrevive.
 *
 * ## La fila queda `active` ANTES de que nadie pida su token
 *
 * `IssueDeviceToken` devuelve `null` para un dispositivo que no lo este, asi que
 * hacerlo al reves produciria una tablet confirmada que no puede recoger nada — y
 * un callejon sin salida que solo se arregla por consola (regla dura 19).
 *
 * ## Los limites del plan no bloquean (ADR-028, regla dura 15)
 *
 * Aqui no se consulta la licencia y no es un olvido: superar `max_devices` **no
 * impide vincular**. `ObservePlanLimits` lo observa y lo deja escrito, pero un
 * hotel que sustituye la tablet averiada de recepcion no se queda sin punto de
 * fichaje en el peor momento posible.
 */
final readonly class ConfirmPairing
{
    public function __construct(
        private PairingRequests $requests,
        private DeviceRegistry $devices,
        private PairingSecrets $secrets,
        private KioskEventPublisher $events,
        private KioskMetrics $metrics,
        private LoggerInterface $log,
        private InstallationSiteProvider $sites,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws InstallationSiteMissing si la instalacion todavia no tiene centro
     */
    public function handle(ConfirmPairingCommand $command): PairingConfirmation
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof InstallationSite) {
            // Antes de la puesta en marcha (RF-PD-03) no hay centro al que
            // vincular. Es un estado de la instalacion, no un error de quien
            // teclea, y por eso sube como excepcion en lugar de confundirse con
            // el rechazo generico del codigo.
            throw new InstallationSiteMissing;
        }

        $now = $this->clock->now();
        $found = $this->requests->findPendingByCodeHash($this->secrets->hash($command->code->value));

        if ($found === null) {
            return $this->rejected('code_unknown', null);
        }

        $request = $found['request'];

        if ($request->confirm($now) === ConfirmOutcome::Rejected) {
            // Solo puede ser caducidad: `findPendingByCodeHash` ya acota a las
            // pendientes. El motivo va al log y no a la respuesta (regla dura 17).
            return $this->rejected('code_expired', $request->uuid);
        }

        try {
            return $this->connection->transaction(
                function () use ($command, $found, $request, $site, $now): PairingConfirmation {
                    $device = $this->devices->provision(
                        siteId: $site->id,
                        name: $command->name->value,
                        appVersion: $found['app_version'],
                        now: $now,
                    );

                    if ($device === null) {
                        // El nombre lo tiene un quiosco ACTIVO. No es un rechazo
                        // del codigo —que sigue siendo valido— sino un `422`
                        // colgado del campo `name`: dos quioscos con el mismo
                        // nombre convierten cualquier diagnostico en una
                        // adivinanza.
                        $this->log->warning('pairing_rejected', [
                            'reason' => 'name_taken',
                            'pairing_id' => $request->uuid,
                        ]);
                        $this->metrics->pairingRejected('name_taken');

                        return PairingConfirmation::nameTaken();
                    }

                    if (! $this->requests->markConfirmed($request->uuid, $device->id, $command->actorUserId, $now)) {
                        // Otra peticion confirmo esta misma solicitud entre la
                        // lectura y la escritura. Lanzar es lo que deshace la
                        // transaccion **incluida el alta de arriba**: dos
                        // `confirm` simultaneos con el mismo codigo dejan un solo
                        // dispositivo.
                        throw new PairingRaceLost;
                    }

                    $this->events->publish(new DeviceProvisioned(
                        deviceId: $device->id,
                        deviceUuid: $device->uuid,
                        name: $device->name,
                        pairingRequestUuid: $request->uuid,
                        reactivated: $device->reactivated,
                        previousStatus: $device->previousStatus,
                        actorUserId: $command->actorUserId,
                        occurredAt: $now,
                    ));

                    // Sin el nombre del quiosco y **sin nada de quien lo
                    // confirmo** salvo su identificador de cuenta: un log tecnico
                    // no lleva nombres (regla dura 21). Quien firmo el acto esta
                    // en `audit_log`, que es donde tiene valor.
                    $this->log->info('pairing_confirmed', [
                        'pairing_id' => $request->uuid,
                        'device_uuid' => $device->uuid,
                        'reactivated' => $device->reactivated,
                    ]);
                    $this->metrics->pairingConfirmed();

                    return PairingConfirmation::confirmed(
                        $device,
                        $found['app_version'],
                        $found['requested_at'],
                    );
                },
            );
        } catch (PairingRaceLost) {
            // El mismo rechazo generico que cualquier otra causa: quien pierde la
            // carrera no tiene por que enterarse de que la hubo (regla dura 17).
            return $this->rejected('race', $request->uuid);
        }
    }

    private function rejected(string $reason, ?string $pairingId): PairingConfirmation
    {
        $this->log->warning('pairing_rejected', [
            'reason' => $reason,
            'pairing_id' => $pairingId,
        ]);
        $this->metrics->pairingRejected($reason);

        return PairingConfirmation::rejected();
    }
}
