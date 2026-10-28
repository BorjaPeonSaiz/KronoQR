<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use DateTimeImmutable;

/**
 * Una ventana de uso de un acceso de soporte: un asiento `support_grant.used`
 * (**RF-PD-11**, ADR-020 §3). Ver {@see SupportGrantUses}.
 *
 * Sin duracion: el asiento no la guarda, y la ventana configurada hoy puede no
 * ser la del dia en que se escribio.
 */
final readonly class SupportGrantUse
{
    public function __construct(
        /** La peticion que abrio la ventana, en UTC. */
        public DateTimeImmutable $startedAt,
        /** Su metodo HTTP, o `null` si el asiento no lo lleva. */
        public ?string $method,
        /** El patron de su ruta —nunca la URL concreta—, o `null` si el asiento no lo lleva. */
        public ?string $route,
    ) {}
}
