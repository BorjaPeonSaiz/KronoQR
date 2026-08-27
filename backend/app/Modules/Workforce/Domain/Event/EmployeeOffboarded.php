<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Una persona ha sido dada de baja (RF-GP-03, RN-14).
 *
 * **Es el evento con mas consecuencias de este modulo** y por eso existe desde
 * la primera version, aunque hoy no lo escuche nadie:
 *
 *   - `Identity` revoca su credencial cuando la tarea 1.5 este en su sitio: un
 *     empleado de baja no ficha, y su tarjeta deja de valer el mismo dia.
 *   - `Compliance` sella el asiento en `audit_log` (tarea 1.14). Una baja es una
 *     accion con relevancia legal: cambia desde cuando esa persona deja de tener
 *     registro horario.
 *   - `Reporting` deja de contarla en la plantilla activa.
 *
 * Escrito asi, ninguno de los tres obliga a volver a tocar el caso de uso de la
 * baja: se suscriben a este evento.
 *
 * `terminatedOn` es la **fecha de cese**, que puede no ser hoy; `occurredAt` es
 * el instante en que se registro la baja. No son lo mismo y confundirlos
 * atribuiria la baja al dia equivocado.
 */
final readonly class EmployeeOffboarded implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $terminatedOn,
        public ?string $reason,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_offboarded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
