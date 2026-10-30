<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Domain\ValueObject\ScheduleType;

/**
 * Los terminos del contrato del periodo nuevo de una readmision (RN-27,
 * RF-GP-02). La vigencia empieza en la fecha de alta de la readmision.
 */
final readonly class RehireContractTerms
{
    public function __construct(
        public float $weeklyHours,
        public ?float $annualHours,
        public ScheduleType $scheduleType,
    ) {}
}
