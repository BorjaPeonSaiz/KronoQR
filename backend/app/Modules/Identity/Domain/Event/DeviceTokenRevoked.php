<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha retirado el token de un quiosco (RF-ID-04, RS-04).
 *
 * Ocurre cuando la tablet se pierde, se sustituye o se retira de servicio. Es
 * auditable por lo mismo que su emision: cambia quien puede escribir en el
 * registro horario.
 */
final readonly class DeviceTokenRevoked implements DomainEvent
{
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        public string $reason,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.device_token_revoked';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
