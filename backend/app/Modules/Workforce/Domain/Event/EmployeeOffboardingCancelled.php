<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha anulado una baja programada antes de su fecha de cese (RN-14, ADR-062
 * §3). La persona sigue en alta y sin fecha de cese; `terminatedOn` es la fecha
 * que se anula. Lo escucha `Compliance` para `employee.offboarding_cancelled`.
 */
final readonly class EmployeeOffboardingCancelled implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $terminatedOn,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_offboarding_cancelled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
