<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * La orden de retirar el token de un quiosco (RF-ID-04, RS-04).
 *
 * `deactivate` distingue las dos operaciones que en la practica se confunden:
 * retirar el token de una tablet que se va a volver a emparejar, y **dar de baja
 * el dispositivo**. La segunda marca `devices.status = revoked` y deja fuera a
 * ese quiosco aunque alguien le devuelva un token: es la respuesta correcta a
 * una tablet robada.
 */
final readonly class RevokeDeviceTokenCommand
{
    public function __construct(
        public string $deviceUuid,
        public string $reason,
        public bool $deactivate = true,
        public ?int $actorUserId = null,
    ) {}
}
