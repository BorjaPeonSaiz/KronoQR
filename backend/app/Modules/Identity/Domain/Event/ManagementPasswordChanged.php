<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El titular de una cuenta de gestion ha cambiado **su propia** contrasena
 * (**RF-ID-10**, `POST /api/v1/auth/password`).
 *
 * Lo sella `Compliance` como `user.password_changed`, con la propia cuenta como
 * actor y sujeto: nadie mas puede hacerlo, porque hace falta la contrasena
 * actual. **Ni la contrasena ni nada derivado de ella** (regla dura 21).
 */
final readonly class ManagementPasswordChanged implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.management_password_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
