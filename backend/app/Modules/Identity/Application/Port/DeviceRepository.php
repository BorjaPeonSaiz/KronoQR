<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\DeviceStatus;

/**
 * Los quioscos, vistos por los casos de uso de este modulo (RF-ID-04).
 *
 * Habla en {@see Device} y en escalares, nunca en modelos Eloquent (ADR-025,
 * restriccion 2).
 */
interface DeviceRepository
{
    public function findByUuid(string $deviceUuid): ?Device;

    /**
     * Guarda el hash del token vigente del dispositivo, o lo borra con `null`.
     *
     * **Solo el hash** (§5.2 aplicado al token de dispositivo, RS-04): el valor
     * en claro existe una sola vez, en la respuesta que lo entrega.
     */
    public function storeTokenHash(int $deviceId, ?string $tokenHash): void;

    public function changeStatus(int $deviceId, DeviceStatus $status): void;
}
