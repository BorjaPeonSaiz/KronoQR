<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha revocado una credencial (RF-QR-03).
 *
 * **Accion con relevancia legal** (regla dura 6): desde este instante, esa
 * tarjeta deja de admitirse y quien la lleve no puede fichar. Si manana hay una
 * discusion sobre una jornada que falta, esta entrada de `audit_log` es la que
 * explica por que.
 *
 * `reason` es el motivo que escribio quien revoco. Va al asiento porque es lo
 * que distingue «se perdio antes de entregarla» de «el empleado la perdio», que
 * son incidencias distintas. **No es sitio para nombres** (regla dura 21): el
 * titular ya viaja como `employeeUuid`.
 */
final readonly class CredentialRevoked implements DomainEvent
{
    public function __construct(
        public int $credentialId,
        public string $credentialUuid,
        public string $employeeUuid,
        public string $reason,
        /** Quien la revoco, o `null` si fue un comando de consola sin persona detras. */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.credential_revoked';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
