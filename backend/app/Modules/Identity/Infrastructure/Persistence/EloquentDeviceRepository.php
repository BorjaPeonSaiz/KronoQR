<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Domain\Model\Device as DeviceEntity;
use App\Modules\Identity\Domain\ValueObject\DeviceStatus;

/**
 * Los quioscos sobre Eloquent.
 *
 * Traduce a {@see DeviceEntity} y no deja salir el modelo: el caso de uso no
 * tiene que poder tocar `pending_queue_size` ni `last_seen_at`, que los declara
 * el propio dispositivo en su latido y son informacion, no autoridad.
 */
final readonly class EloquentDeviceRepository implements DeviceRepository
{
    public function findByUuid(string $deviceUuid): ?DeviceEntity
    {
        $row = Device::query()->where('uuid', $deviceUuid)->first();

        if (! $row instanceof Device) {
            return null;
        }

        return new DeviceEntity(
            id: $row->id,
            uuid: $row->uuid,
            siteId: $row->site_id,
            name: $row->name,
            status: DeviceStatus::from($row->status),
            tokenHash: $row->token_hash,
        );
    }

    public function storeTokenHash(int $deviceId, ?string $tokenHash): void
    {
        Device::query()->whereKey($deviceId)->update(['token_hash' => $tokenHash]);
    }

    public function changeStatus(int $deviceId, DeviceStatus $status): void
    {
        Device::query()->whereKey($deviceId)->update(['status' => $status->value]);
    }
}
