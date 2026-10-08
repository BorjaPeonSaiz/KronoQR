<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Shared\Application\Port\RetentionYearsFloor;

/** El suelo de años de conservacion que se le diga. */
final readonly class FixedRetentionYearsFloor implements RetentionYearsFloor
{
    public function __construct(private int $years = 1) {}

    public function minimumRetentionYears(): int
    {
        return $this->years;
    }
}
