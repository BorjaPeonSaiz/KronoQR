<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha programado —o reprogramado— la baja de una persona (RN-14, RF-GP-03,
 * ADR-062 §3): tiene fecha de cese y sigue en alta hasta que la tarea del sistema
 * la aplique.
 *
 * Nadie revoca nada por este evento: tarjetas, PIN y portal siguen igual hasta
 * su ultimo dia. Lo escucha `Compliance` para el asiento
 * `employee.offboarding_scheduled`. `previousTerminatedOn` es la fecha anterior
 * al reprogramar, y `null` la primera vez.
 */
final readonly class EmployeeOffboardingScheduled implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $terminatedOn,
        public ?string $previousTerminatedOn,
        public ?string $reason,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_offboarding_scheduled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
