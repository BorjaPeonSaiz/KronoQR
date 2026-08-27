<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha emitido una credencial y queda **pendiente de imprimir** (RF-QR-01,
 * RF-QR-03).
 *
 * **Es un hecho con relevancia legal y por eso existe** (regla dura 6): a partir
 * de aqui hay una persona con derecho a tarjeta. `Compliance` lo recoge y sella
 * el asiento en `audit_log`; el panel de estado de RF-QR-08 lo usa para marcarla
 * como «pendiente de imprimir».
 *
 * **Todavia no hay tarjeta capaz de fichar.** Eso ocurre al imprimir, que es
 * cuando se acuña el token (ADR-034) y lo dice `CredentialPrinted` — el evento
 * que anade la tarea 1.10 y el que lleva el `key_id`, porque hasta ese momento
 * no hay ninguno.
 *
 * **Lo que NO lleva**: ni el token, ni su hash, ni el nombre del titular
 * (reglas duras 10 y 21). Con `employeeUuid` y `credentialUuid` se reconstruye
 * cualquier investigacion, y ninguno de los dos sirve para fabricar una tarjeta.
 *
 * `reissue` distingue la primera emision de una reemision por perdida o
 * deterioro: son la misma operacion tecnica y dos hechos distintos para quien
 * despues tenga que explicar por que una persona tuvo tres tarjetas en un año.
 */
final readonly class CredentialIssued implements DomainEvent
{
    public function __construct(
        public int $credentialId,
        public string $credentialUuid,
        public string $employeeUuid,
        /** Si sustituye a una credencial anterior que se ha revocado en el mismo acto. */
        public bool $reissue,
        /** Quien la emitio, o `null` si fue un comando de consola sin persona detras. */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return $this->reissue ? 'identity.credential_reissued' : 'identity.credential_issued';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
