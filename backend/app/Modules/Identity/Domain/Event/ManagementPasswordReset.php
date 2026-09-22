<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha sustituido la contrasena de una cuenta de gestion, y con ella se han
 * cerrado sus sesiones abiertas (RS-06, OWASP A07).
 *
 * **Deja asiento por la misma razon que {@see TwoFactorReset}**: cambiarle la
 * contrasena a otra persona es, en manos de un administrador comprometido, la
 * via mas comoda de prepararse el acceso a su cuenta. Que conste quien lo hizo y
 * cuando es lo que separa «le restableci la contrasena porque me lo pidio» de un
 * acceso indebido que nadie puede reconstruir.
 *
 * **Ni la contrasena ni nada derivado de ella viajan en este evento**, ni
 * siquiera su longitud: el hecho auditable es que la credencial se sustituyo, no
 * cual es. Tampoco el nombre ni el correo (regla dura 21).
 */
final readonly class ManagementPasswordReset implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        /** Quien la restablecio, o `null` si fue un comando de consola sin sesion detras. */
        public ?string $actorUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.management_password_reset';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
