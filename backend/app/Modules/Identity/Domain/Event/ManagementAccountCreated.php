<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha dado de alta una cuenta de gestion con contrasena temporal
 * (**RF-ID-10**; `POST /api/v1/management-accounts` o `identity:create-user`).
 *
 * Lo sella `Compliance` como `user.created` en la misma transaccion que el alta
 * (ADR-010). El rol va en su propio evento, `ManagementRoleAssigned`, que es el
 * que ya usan el asistente y la consola: son dos hechos y dos asientos.
 *
 * **Solo el `uuid` de la cuenta y el del actor** (regla dura 21): ni el correo,
 * ni el nombre, ni la contrasena ni nada derivado de ella.
 */
final readonly class ManagementAccountCreated implements DomainEvent
{
    /**
     * @param  string|null  $actorUuid  Quien la da de alta. `null` en consola, donde no hay
     *                                  sesion: el asiento sale a nombre del sistema.
     */
    public function __construct(
        public string $userUuid,
        public ?string $actorUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.management_account_created';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
