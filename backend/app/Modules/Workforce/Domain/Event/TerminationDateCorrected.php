<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha corregido la fecha de cese de un periodo con baja efectiva (RN-27,
 * RL-04, ADR-062 §7). Lo escucha `Compliance` para el asiento
 * `employee.termination_date_corrected`.
 *
 * **Sin el motivo**: vive en `employment_period_corrections`, con
 * `correctionId`, y puede llevar texto libre (regla dura 21).
 */
final readonly class TerminationDateCorrected implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $employmentPeriodUuid,
        public string $previousTerminatedOn,
        public string $terminatedOn,
        public int $correctionId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.termination_date_corrected';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
