<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Ha cambiado la ficha de un empleado (RF-GP-01).
 *
 * Lleva **la lista de campos tocados y no sus valores**: es lo que la auditoria
 * necesita para decir «se cambio el departamento» sin que el nombre, el correo
 * o la adscripcion de una persona acaben copiados en un log tecnico (regla dura
 * 21). El valor anterior y el nuevo son de `audit_log`, que si es el sitio donde
 * eso tiene amparo legal y control de acceso.
 */
final readonly class EmployeeProfileUpdated implements DomainEvent
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $employeeUuid,
        public array $changedFields,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_profile_updated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
