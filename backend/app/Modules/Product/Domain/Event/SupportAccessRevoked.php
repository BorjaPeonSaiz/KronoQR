<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha retirado un acceso de soporte (**RF-PD-11**, ADR-020, regla dura 6).
 *
 * ## Por que se audita, si conceder ya se audito
 *
 * Porque lo que importa no es que existiera la concesion, sino **hasta cuando
 * duro de verdad**. Una concedida por 72 horas y revocada a los diez minutos y
 * otra usada las 72 enteras dejan el mismo asiento de concesion y son dos
 * hechos completamente distintos ante una pregunta del art. 28 RGPD.
 *
 * ## Solo se publica cuando la revocacion OCURRE
 *
 * Revocar una concesion ya revocada devuelve `204` y **no publica nada**: la
 * segunda pulsacion de un boton no es un hecho nuevo, y el trail describe
 * hechos. La fila no se borra en ningun caso (regla dura 5).
 */
final readonly class SupportAccessRevoked implements DomainEvent
{
    public function __construct(
        public int $grantId,
        public string $grantUuid,
        public string $scope,
        /**
         * Quien la revoco, o `null` desde la consola.
         *
         * Nulo es informacion, no un hueco: distingue «la retiro Marta desde el
         * panel» de «la retiro quien tiene acceso al servidor», que son dos
         * personas distintas en casi cualquier hotel.
         */
        public ?int $revokedByUserId,
        /** Si en el momento de revocarla el acceso todavia servia para algo. */
        public bool $wasActive,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.support_access_revoked';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
