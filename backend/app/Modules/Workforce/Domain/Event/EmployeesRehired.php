<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha readmitido a varias personas a la vez (RN-27, ADR-062 §6).
 *
 * Cada una deja su {@see EmployeeRehired} y su asiento. Este evento solo existe
 * para que el uso del plan se cuente **una vez por lote** y no una por persona,
 * como la importacion (ADR-028): sin asiento propio.
 */
final readonly class EmployeesRehired implements DomainEvent
{
    public function __construct(
        public int $rehired,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employees_rehired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
