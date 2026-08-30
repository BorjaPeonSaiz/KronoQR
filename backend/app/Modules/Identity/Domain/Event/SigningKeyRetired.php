<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cerrado el solape: la clave saliente ya no verifica ninguna tarjeta
 * activa (RF-QR-07, doc 02 §5.3).
 *
 * **Este asiento es el que fecha la muerte de un lote de tarjetas.** Si dentro
 * de dos años alguien pregunta por que un QR impreso en 2026 dejo de funcionar,
 * la respuesta es este registro y el `credential.revoked` de su tarjeta. Sin el,
 * la unica explicacion posible seria «alguien cambio una variable de entorno», y
 * eso no consta en ninguna parte.
 *
 * **No retira nada por si mismo.** El acto material —vaciar
 * `QR_SIGNING_KEY_PREVIOUS`— lo hace el operador en el gestor de secretos, fuera
 * de la aplicacion (regla dura 13). Lo que este evento certifica es que el
 * sistema comprobo que ya no quedaba ninguna credencial activa firmada con esa
 * clave, y cuando lo comprobo.
 */
final readonly class SigningKeyRetired implements DomainEvent
{
    public function __construct(
        public string $keyId,
        /** Cuantas tarjetas llego a firmar en toda su vida. Solo informativo. */
        public int $signedCredentials,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.signing_key_retired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
