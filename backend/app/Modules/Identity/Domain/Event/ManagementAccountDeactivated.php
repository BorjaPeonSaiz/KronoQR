<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha dado de baja una cuenta de gestion: deja de poder entrar y sus sesiones
 * abiertas dejan de valer (RS-05, RS-06, RL-16).
 *
 * **Es el hecho que el producto no sabia contar.** `users.is_active` se
 * consultaba al autenticar desde la primera fase, pero nada lo ponia a `false`:
 * la unica baja posible era editar la fila a mano en PostgreSQL, fuera del
 * producto y por tanto fuera del trail. Una cuenta de `rrhh` que sobrevive a
 * quien la usaba es acceso a los datos de toda la plantilla y a la correccion de
 * jornadas, que es donde se falsea un registro horario.
 *
 * `reason` es texto de quien lo ejecuta —«baja del 30/09»—, no un catalogo, por
 * lo mismo que en {@see TwoFactorReset}: es un hecho operativo poco frecuente y
 * un enum aqui envejeceria mal. **No es sitio para nombres ni para correos**
 * (regla dura 21): el titular viaja como `userUuid` y nada mas.
 */
final readonly class ManagementAccountDeactivated implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        public string $reason,
        /** Quien la dio de baja, o `null` si fue un comando de consola sin sesion detras. */
        public ?string $actorUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.management_account_deactivated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
