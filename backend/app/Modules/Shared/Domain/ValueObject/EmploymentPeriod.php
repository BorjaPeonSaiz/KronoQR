<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un periodo de alta de una persona (RN-27, ADR-062 §1): de su fecha de alta a
 * su fecha de cese, si la hay.
 *
 * **Vive en `Shared`** porque lo leen dos modulos que no pueden importarse:
 * `Workforce`, que lo escribe, y `Attendance`, que decide con el que jornadas
 * admiten un tramo a mano (RF-PA-04). Es la misma razon que
 * {@see EmployeeSnapshot}.
 *
 * Las fechas son **civiles** (`Y-m-d`) y se comparan como cadenas, que ordenan
 * igual que las fechas. `offboarded` dice si la baja de ese periodo ya es
 * efectiva; con fecha de cese y sin ella, la baja esta programada.
 */
final readonly class EmploymentPeriod
{
    public const string STATE_ACTIVE = 'active';

    public const string STATE_SCHEDULED = 'scheduled';

    public const string STATE_TERMINATED = 'terminated';

    public function __construct(
        public string $hiredOn,
        public ?string $terminatedOn = null,
        public bool $offboarded = false,
        /** Identificador publico del periodo, o `null` si aun no se ha guardado. */
        public ?string $uuid = null,
    ) {
        self::assertCivilDate('hiredOn', $hiredOn);

        if ($terminatedOn !== null) {
            self::assertCivilDate('terminatedOn', $terminatedOn);

            if ($terminatedOn < $hiredOn) {
                throw new InvalidArgumentException('La fecha de cese de un periodo de alta no puede ser anterior a la de alta.');
            }
        }

        if ($offboarded && $terminatedOn === null) {
            throw new InvalidArgumentException('Un periodo con la baja efectiva necesita su fecha de cese.');
        }
    }

    /**
     * Si el dia civil `$date` (`Y-m-d`) cae dentro del periodo, ambos extremos
     * incluidos.
     */
    public function covers(string $date): bool
    {
        return $date >= $this->hiredOn && ($this->terminatedOn === null || $date <= $this->terminatedOn);
    }

    /**
     * `active` sin fecha de cese, `scheduled` con una baja programada y
     * `terminated` con la baja efectiva.
     */
    public function state(): string
    {
        if ($this->offboarded) {
            return self::STATE_TERMINATED;
        }

        return $this->terminatedOn === null ? self::STATE_ACTIVE : self::STATE_SCHEDULED;
    }

    /**
     * El dia civil `$date` cae en el hueco entre dos periodos de una misma
     * persona: despues del cese de `$this` y antes del alta de `$next`.
     */
    public function gapBefore(self $next, string $date): bool
    {
        return $this->terminatedOn !== null && $date > $this->terminatedOn && $date < $next->hiredOn;
    }

    private static function assertCivilDate(string $field, string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('EmploymentPeriod::'.$field.' tiene que ser una fecha civil Y-m-d valida.');
        }
    }
}
