<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Ha cambiado la ficha de un empleado (RF-GP-01).
 *
 * Lleva **la lista de campos tocados y no sus valores**: es lo que la auditoria
 * necesita para decir «se cambio el nombre» sin que el nombre o el correo de
 * una persona acaben copiados en un log tecnico (regla dura 21).
 *
 * **La adscripcion es la excepcion** (R4-SC-05): lleva el departamento de antes
 * y el de despues. Un cambio de departamento cambia que responsable ve y corrige
 * la jornada de esa persona (RF-ID-03), y «se cambio el departamento» sin decir
 * de cual a cual no permite reconstruir quien tuvo autoridad sobre ella y
 * cuando. Son identificadores de la organizacion —el mismo `department_id` que
 * ya lleva `employee.hired`—, no datos de la persona. Si el departamento no
 * cambio, los dos valen lo mismo y nadie los lee.
 */
final readonly class EmployeeProfileUpdated implements DomainEvent
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $employeeUuid,
        public array $changedFields,
        public ?int $previousDepartmentId,
        public ?int $departmentId,
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

    /**
     * Si el cambio movio a la persona de departamento. Se decide por los
     * valores y no por `changedFields`, para que un evento armado a mano con la
     * lista incompleta no esconda el traslado.
     */
    public function changedDepartment(): bool
    {
        return $this->previousDepartmentId !== $this->departmentId;
    }
}
