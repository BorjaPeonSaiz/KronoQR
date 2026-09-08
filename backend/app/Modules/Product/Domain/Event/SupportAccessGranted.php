<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El cliente ha concedido al fabricante un acceso temporal (**RF-PD-11**,
 * RL-18, ADR-020, regla dura 6).
 *
 * ## Por que se audita
 *
 * Porque **es el consentimiento**. Durante la intervencion el fabricante actua
 * como encargado del tratamiento para ese supuesto concreto (RL-18), y eso exige
 * el contrato de encargo del art. 28 RGPD; lo que acredita que el cliente lo
 * autorizo, para que incidente y por cuanto tiempo es este asiento y nada mas.
 * `Compliance` lo sella como `support_grant.granted`.
 *
 * Por un evento y no por una llamada directa porque el §1.6 no concede la arista
 * `Product -> Compliance`: la misma via que la licencia (5.3), la configuracion
 * (5.1) y el perfil de cumplimiento (5.2).
 *
 * ## Lo que NO viaja
 *
 * **El token.** Ni entero ni su hash. Sale una sola vez, en la respuesta que lo
 * entrega, y no se escribe en ningun sitio del que se pueda leer despues — y
 * `audit_log` se conserva cuatro años y se exporta. Tampoco viaja ningun dato de
 * empleado: aqui no los hay (regla dura 21).
 */
final readonly class SupportAccessGranted implements DomainEvent
{
    public function __construct(
        /** `support_grants.id`. Es el `actor_id` con el que actuara la concesion. */
        public int $grantId,
        public string $grantUuid,
        public string $scope,
        public int $hours,
        public DateTimeImmutable $expiresAt,
        /**
         * El motivo, tal cual. Es lo que convierte «alguien entro el martes» en
         * «entro por esto», y sin el no se puede ver el abuso que ADR-020
         * describe: usar la sesion abierta para un incidente distinto.
         */
        public string $reason,
        /** La cuenta que lo autorizo. Nunca su nombre (regla dura 21). */
        public int $grantedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.support_access_granted';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
