<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\RevokeDeviceTokenCommand;
use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\DeviceTokenRevoked;
use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\DeviceStatus;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Retira el token de un quiosco (RF-ID-04, RS-04).
 *
 * **Tiene efecto en la peticion siguiente, no en 90 dias.** Se borran los tokens
 * del dispositivo y —salvo que se pida lo contrario— se marca la fila como
 * revocada, que es la comprobacion que hace `IdentityServiceProvider` en cada
 * peticion autenticada. Es la respuesta a una tablet robada, y por eso no puede
 * depender de que caduque nada.
 */
final readonly class RevokeDeviceToken
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Devuelve si habia un dispositivo que revocar.
     */
    public function handle(RevokeDeviceTokenCommand $command): bool
    {
        $device = $this->devices->findByUuid($command->deviceUuid);

        if (! $device instanceof Device) {
            return false;
        }

        $this->connection->transaction(function () use ($command, $device): void {
            $this->tokens->revokeAllFor($device);
            $this->devices->storeTokenHash($device->id, null);

            if ($command->deactivate) {
                $this->devices->changeStatus($device->id, DeviceStatus::REVOKED);
            }

            $this->events->publish(new DeviceTokenRevoked(
                deviceId: $device->id,
                deviceUuid: $device->uuid,
                reason: $command->reason,
                actorUserId: $command->actorUserId,
                occurredAt: $this->clock->now(),
            ));
        });

        return true;
    }
}
