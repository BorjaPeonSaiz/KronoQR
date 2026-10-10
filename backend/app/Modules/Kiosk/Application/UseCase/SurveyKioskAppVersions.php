<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;
use App\Modules\Kiosk\Domain\ValueObject\AppVersionStanding;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\DeployedVersionProvider;
use App\Modules\Shared\Application\Port\KioskAppVersions;
use App\Modules\Shared\Domain\ValueObject\KioskAppVersionSurvey;

/**
 * Que quioscos van por detras —o por delante— de la version del servidor, para
 * la sonda `kiosk.app_version` de `product:doctor` (RF-KI-07, RF-PD-13; bloque
 * 1 de la 2.2.1).
 *
 * ## La misma lectura y la misma regla que la salud del quiosco
 *
 * Lee `DeviceRegistry::all()` —la consulta de `GET /api/v1/devices` y de
 * `kiosk:health`— y juzga con {@see AppVersionPolicy}, construida igual que en
 * {@see CheckKioskHealth}. No hay un segundo criterio: si `doctor` y el panel
 * dijeran cosas distintas de la misma tablet, la conversacion siguiente seria
 * cual de los dos miente.
 *
 * **Lo que añade** es mirar la version **sin la escala de prioridades** de
 * `KioskHealthRow`: alli `app_version_behind` es el ultimo de los avisos y una
 * tablet con cola pendiente sale como `queue_pending` aunque tambien vaya por
 * detras. `doctor` se ejecuta tras una actualizacion, justo cuando las tablets
 * aun drenan su cola, y es entonces cuando hay que saber cual no se ha puesto
 * al dia.
 *
 * ## Solo quioscos activos con latido reciente
 *
 * El plazo es el de silencio de `kiosk:health` (`silentAfterSeconds`): una
 * tablet que ha pasado de el ya sale en `kiosk:health` y en el panel por
 * callada, y su version declarada puede ser de hace semanas.
 *
 * ## Solo lectura
 *
 * Ni transaccion, ni auditoria, ni evento, por lo mismo que
 * {@see CheckKioskHealth}: no se divulga ni un dato personal y no cambia nada.
 */
final readonly class SurveyKioskAppVersions implements KioskAppVersions
{
    private const string ACTIVE = 'active';

    public function __construct(
        private DeviceRegistry $devices,
        private Clock $clock,
        private KioskHealthThresholds $thresholds,
        private DeployedVersionProvider $versions,
    ) {}

    #[\Override]
    public function survey(): KioskAppVersionSurvey
    {
        $policy = AppVersionPolicy::forDeployed($this->versions->deployedVersion());
        $minimum = $policy->minimumAppVersion();

        if ($minimum === null) {
            return KioskAppVersionSurvey::unchecked();
        }

        $now = $this->clock->now()->getTimestamp();
        $examined = 0;
        $behind = [];
        $ahead = [];

        foreach ($this->devices->all() as $device) {
            if ($device->status !== self::ACTIVE || $device->lastSeenAt === null) {
                continue;
            }

            if ($now - $device->lastSeenAt->getTimestamp() > $this->thresholds->silentAfterSeconds) {
                continue;
            }

            $examined++;

            match ($policy->standingOf($device->appVersion)) {
                AppVersionStanding::Behind => $behind[$device->uuid] = $device->appVersion,
                AppVersionStanding::Ahead => $ahead[$device->uuid] = $device->appVersion,
                AppVersionStanding::Current, AppVersionStanding::Unchecked => null,
            };
        }

        return new KioskAppVersionSurvey($minimum, $examined, $behind, $ahead);
    }
}
