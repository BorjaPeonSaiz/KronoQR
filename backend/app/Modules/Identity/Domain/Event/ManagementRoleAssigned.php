<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;

/**
 * Se le ha asignado un rol a una cuenta de gestion (RF-ID-02, RS-05).
 *
 * **Bloque D, familia «cambia roles, permisos o configuracion».** Un rol decide
 * quien puede corregir horas y quien puede ver la plantilla entera: sin asiento,
 * la pregunta «¿quien le dio acceso a esta persona al registro de todo el hotel?»
 * no tiene respuesta, y es justo la que se hace despues de un incidente.
 *
 * **Hoy solo lo produce `identity:create-user`**, que es la unica via del producto
 * para asignar un rol: la API no tiene endpoints de gestion de usuarios todavia.
 * Cuando los tenga, publicaran este mismo evento y el asiento no cambia.
 */
final readonly class ManagementRoleAssigned implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        public UserRole $role,
        /** Quien lo asigno, o `null` si fue un comando de consola sin sesion detras. */
        public ?string $actorUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.management_role_assigned';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
