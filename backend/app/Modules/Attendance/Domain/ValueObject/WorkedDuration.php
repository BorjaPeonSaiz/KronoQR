<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\NegativeWorkedDuration;

/**
 * Tiempo trabajado, en minutos y nunca negativo (doc 01 §5.3).
 *
 * En minutos porque esa es la unidad del registro: `shift_entries.
 * duration_minutes` y `daily_totals.total_minutes`. Los segundos sobrantes de
 * un tramo se truncan una sola vez, al medir el intervalo, y no se arrastran:
 * si el total del dia se calculase sobre segundos y se truncase al final,
 * dejaria de ser la suma de lo que muestra cada tramo, y quien revise su
 * jornada sumaria a mano y le saldria otra cosa.
 *
 * El constructor rechaza lo negativo; nadie lo comprueba despues. Un objeto de
 * valor que no puede representar un estado imposible ahorra la validacion en
 * cada uno de los sitios que lo suman.
 */
final readonly class WorkedDuration
{
    private function __construct(public int $minutes)
    {
        if ($minutes < 0) {
            throw NegativeWorkedDuration::ofMinutes($minutes);
        }
    }

    public static function ofMinutes(int $minutes): self
    {
        return new self($minutes);
    }

    /**
     * Los umbrales del negocio se enuncian en horas —12 h de tramo anomalo,
     * 9 h de jornada—, asi que se escriben en horas y se comparan en minutos.
     */
    public static function ofHours(int $hours): self
    {
        return new self($hours * 60);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->minutes + $other->minutes);
    }

    public function isZero(): bool
    {
        return $this->minutes === 0;
    }

    public function isShorterThan(self $other): bool
    {
        return $this->minutes < $other->minutes;
    }

    public function isLongerThan(self $other): bool
    {
        return $this->minutes > $other->minutes;
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }
}
