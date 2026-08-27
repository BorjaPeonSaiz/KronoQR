<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha dado de alta a una persona en la plantilla (RF-GP-01).
 *
 * **Por que un evento en un modulo de soporte.** Porque el alta tiene
 * consecuencias fuera de `Workforce` y ninguna de ellas puede resolverse
 * llamando a otro modulo: la credencial que hay que emitir es de `Identity`
 * (tarea 1.5), el PIN es de la 1.13 y el asiento de auditoria es de la 1.14. El
 * evento es la unica via por la que este modulo puede provocar esos efectos sin
 * depender de quien los ejecuta (doc 02 §1.6).
 *
 * **Sin datos personales.** Viaja el `uuid` y nada mas de la persona: este
 * evento acaba en logs y en metricas, y ahi solo se identifica por UUID (regla
 * dura 21).
 */
final readonly class EmployeeHired implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public ?int $departmentId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_hired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
