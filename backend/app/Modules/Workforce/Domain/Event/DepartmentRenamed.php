<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cambiado un departamento (RF-GP-02, AUD-2): hoy solo se le puede
 * cambiar el nombre.
 *
 * Deja asiento `department.renamed` en `audit_log` (regla dura 6). Lleva **la
 * lista de campos tocados y no sus valores**, con la misma convencion que
 * {@see EmployeeProfileUpdated}; si el dia de mañana el departamento gana otro
 * campo editable, entra en `changedFields` sin tocar el evento.
 */
final readonly class DepartmentRenamed implements DomainEvent
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public int $departmentId,
        public array $changedFields,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.department_renamed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
