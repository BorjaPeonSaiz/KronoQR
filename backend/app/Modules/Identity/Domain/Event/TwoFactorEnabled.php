<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Una cuenta de gestion ha activado su segundo factor (RF-ID-01, RS-06).
 *
 * **Accion con relevancia legal** (regla dura 6): es la emision de una credencial
 * de acceso, igual que la tarjeta o el PIN, y por eso cae en la misma familia del
 * bloque D. A partir de este instante, entrar con esa cuenta exige el telefono en
 * el que se dio de alta; si manana alguien discute un acceso, esta entrada dice
 * desde cuando.
 *
 * **No lleva el secreto, ni su hash, ni un fragmento.** El secreto vive cifrado en
 * `users.two_factor_secret` y no tiene por que existir en ningun otro sitio; en
 * `audit_log`, ademas, viviria cuatro años dentro de la tabla que se enseña en una
 * inspeccion. Lo que se audita es que se activo un segundo factor, no cual.
 */
final readonly class TwoFactorEnabled implements DomainEvent
{
    public function __construct(
        public string $userUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.two_factor_enabled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
