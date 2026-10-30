<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use InvalidArgumentException;

/**
 * La historia laboral de una persona: sus periodos de alta, del mas antiguo al
 * mas reciente (RN-27, ADR-062 §1).
 *
 * Las mismas dos afirmaciones que `employment_periods_no_overlap` y
 * `one_open_employment_period` en la base, aqui para que el dominio las pueda
 * usar y probar sin ella: los periodos **no se solapan** y **solo el ultimo
 * puede no tener la baja efectiva**. El ultimo es el periodo en curso, el que
 * proyectan `hired_at`, `terminated_at` y `status` de la ficha.
 */
final readonly class EmploymentHistory
{
    /** @var list<EmploymentPeriod> */
    public array $periods;

    /**
     * @param  list<EmploymentPeriod>  $periods  en cualquier orden
     */
    public function __construct(array $periods)
    {
        usort($periods, static fn (EmploymentPeriod $a, EmploymentPeriod $b): int => strcmp($a->hiredOn, $b->hiredOn));

        $previous = null;

        foreach ($periods as $period) {
            if ($previous instanceof EmploymentPeriod) {
                self::assertFollows($previous, $period);
            }

            $previous = $period;
        }

        $this->periods = $periods;
    }

    /**
     * El periodo en curso: el de fecha de alta mas reciente.
     */
    public function current(): ?EmploymentPeriod
    {
        return $this->periods === [] ? null : $this->periods[\count($this->periods) - 1];
    }

    public function find(string $uuid): ?EmploymentPeriod
    {
        foreach ($this->periods as $period) {
            if ($period->uuid === $uuid) {
                return $period;
            }
        }

        return null;
    }

    /**
     * El periodo que sigue a `$period`, o `null` si es el ultimo.
     */
    public function after(EmploymentPeriod $period): ?EmploymentPeriod
    {
        foreach ($this->periods as $candidate) {
            if ($candidate->hiredOn > $period->hiredOn) {
                return $candidate;
            }
        }

        return null;
    }

    public function isCurrent(EmploymentPeriod $period): bool
    {
        return $this->current()?->hiredOn === $period->hiredOn;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertFollows(EmploymentPeriod $previous, EmploymentPeriod $next): void
    {
        if (! $previous->offboarded) {
            throw new InvalidArgumentException('Solo el ultimo periodo de alta puede no tener la baja efectiva (RN-27).');
        }

        if ($previous->terminatedOn === null || $next->hiredOn <= $previous->terminatedOn) {
            throw new InvalidArgumentException('Los periodos de alta de una persona no se solapan (RN-27).');
        }
    }
}
