<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\Policy\ClockingPolicy;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;

/**
 * La politica de RN-07 y RN-08 con sus umbrales escritos donde se leen.
 *
 * `standard()` es el perfil de serie que documenta `ClockingPolicy`: 1 minuto de
 * duracion minima computable y 12 h de tramo anomalo. Los dos son configuracion
 * por instalacion (regla dura 14), asi que ninguna prueba los da por sabidos:
 * la que va a por un limite lo escribe.
 */
final class ClockingPolicyFactory
{
    private int $minimumMinutes = 1;

    private int $anomalousMinutes = 720;

    public static function new(): self
    {
        return new self;
    }

    /** 1 minuto y 12 h, el perfil de serie. */
    public static function standard(): ClockingPolicy
    {
        return self::new()->build();
    }

    public function withMinimumComputableMinutes(int $minutes): self
    {
        $clone = clone $this;
        $clone->minimumMinutes = $minutes;

        return $clone;
    }

    public function withAnomalousAfterMinutes(int $minutes): self
    {
        $clone = clone $this;
        $clone->anomalousMinutes = $minutes;

        return $clone;
    }

    public function withAnomalousAfterHours(int $hours): self
    {
        return $this->withAnomalousAfterMinutes($hours * 60);
    }

    public function build(): ClockingPolicy
    {
        return new ClockingPolicy(
            WorkedDuration::ofMinutes($this->minimumMinutes),
            WorkedDuration::ofMinutes($this->anomalousMinutes),
        );
    }
}
