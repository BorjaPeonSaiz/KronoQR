<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El PIN se ha entregado en mano (RF-ID-09).
 *
 * **La entrega es un acto presencial y por eso existe este evento.** El producto
 * no depende del correo del empleado (regla dura 12, ADR-015): no hay envio, ni
 * enlace de recuperacion, ni invitacion. Lo que hay es alguien que entrega y
 * alguien que recibe, y un asiento en `audit_log` que dice quien, a quien y
 * cuando.
 *
 * Se entrega en el mismo acto que la tarjeta y la hoja de instrucciones: es un
 * unico momento presencial, no tres.
 */
final readonly class EmployeePinDelivered implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        /**
         * Cuenta de gestion que hizo la entrega, por su UUID publico.
         *
         * Aqui si viaja el responsable, al contrario que en la emision: no es
         * contexto de la peticion, es **el hecho**. La entrega existe
         * precisamente para poder decir quien la hizo.
         */
        public string $deliveredByUserUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_pin_delivered';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
