<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha abierto una rotacion de la clave de firma con solape (RF-QR-07, doc 02
 * §5.3).
 *
 * **Es un hecho con relevancia legal** (regla dura 6): a partir de aqui existe
 * una fecha a partir de la cual las tarjetas firmadas con la clave saliente
 * estan condenadas, y una reemision pendiente de imprimir por cada una de
 * ellas. Meses despues, es lo unico que explica por que una persona tuvo dos
 * tarjetas sin haber perdido ninguna.
 *
 * **Lo que NO lleva**: ninguna clave, ni entera ni troceada. Solo los dos
 * `key_id`, que son publicos por construccion —van impresos en cada tarjeta
 * (ADR-005)— y los recuentos.
 *
 * **La reemision de cada tarjeta tiene su propio evento** (`CredentialIssued`
 * con `reissue: true`, que sella `credential.reissued`). Este describe el acto
 * de conjunto, no las filas.
 */
final readonly class SigningKeyRotated implements DomainEvent
{
    public function __construct(
        /** La clave que deja de firmar y que solo verifica mientras dure el solape. */
        public string $retiringKeyId,
        /** La clave con la que se firmara todo lo que se imprima desde ahora. */
        public string $currentKeyId,
        /** Cuantas credenciales se han reemitido en este acto. */
        public int $reissued,
        /** Cuantas se han saltado porque ya tenian una reemision pendiente de imprimir. */
        public int $alreadyPending,
        /** Quien la ejecuto, o `null` si fue un comando de consola sin persona detras. */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.signing_key_rotated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
