<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha retirado el segundo factor de una cuenta de gestion, que vuelve a tener
 * que darlo de alta en su siguiente acceso (RF-ID-01, RS-06).
 *
 * **Es el hecho mas delicado de los tres de `auth.two_factor_*`**, y por eso deja
 * asiento con motivo. Retirar el segundo factor de alguien deja su cuenta a un
 * paso de la contrasena, asi que quien lo haga tiene que quedar escrito: es la
 * via por la que un administrador podria prepararse el acceso a la cuenta de otro.
 *
 * `reason` es texto de quien lo ejecuta —«telefono perdido»—, no un catalogo: es
 * un hecho operativo poco frecuente y un enum aqui envejeceria mal. **No es sitio
 * para nombres** (regla dura 21): el titular ya viaja como `userUuid`.
 */
final readonly class TwoFactorReset implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        public string $reason,
        /** Quien lo retiro, o `null` si fue un comando de consola sin sesion detras. */
        public ?string $actorUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.two_factor_reset';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
