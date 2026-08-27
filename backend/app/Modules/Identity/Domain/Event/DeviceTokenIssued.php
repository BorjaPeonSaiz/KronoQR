<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un quiosco ha recibido un token de dispositivo (RF-ID-04, RS-04).
 *
 * **Es un hecho auditable**: a partir de aqui hay una tablet capaz de registrar
 * fichajes contra el servidor. `Compliance` lo sella en `audit_log` como
 * `device.paired`; que exista la entrada es lo que permite responder «¿desde que
 * dispositivo entro este fichaje y desde cuando estaba autorizado?».
 *
 * `rotation` distingue el emparejamiento inicial de la renovacion automatica al
 * 80 % de vida: la segunda ocurre sola, muchas veces, y no debe leerse en el
 * trail como si alguien hubiera vuelto a emparejar la tablet.
 *
 * **Nunca lleva el token ni su hash.**
 */
final readonly class DeviceTokenIssued implements DomainEvent
{
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        /** Ambitos concedidos, que son los tres del §7.3 y ninguno mas. */
        public string $abilities,
        public DateTimeImmutable $expiresAt,
        public bool $rotation,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return $this->rotation ? 'identity.device_token_rotated' : 'identity.device_token_issued';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
