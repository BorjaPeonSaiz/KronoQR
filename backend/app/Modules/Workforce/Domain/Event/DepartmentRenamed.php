<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cambiado un departamento (RF-GP-02, AUD-2): hoy solo se le puede
 * cambiar el nombre.
 *
 * Deja asiento `department.renamed` en `audit_log` (regla dura 6) con la lista
 * de campos tocados y **el nombre anterior y el nuevo** (R7-RV-02): un asiento
 * que solo dice `['name']` no permite saber como se llamaba el departamento al
 * que apuntan los asientos anteriores. El nombre de un departamento es un dato
 * de la organizacion («Pisos», «Cocina»), no de una persona: no esta sujeto a la
 * regla dura 21, igual que el `department_id`.
 */
final readonly class DepartmentRenamed implements DomainEvent
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public int $departmentId,
        public array $changedFields,
        public string $previousName,
        public string $name,
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
