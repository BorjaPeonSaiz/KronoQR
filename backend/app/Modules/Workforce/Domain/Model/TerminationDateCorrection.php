<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Workforce\Domain\Exception\InvalidTerminationCorrection;
use App\Modules\Workforce\Domain\Exception\OffboardingNotEffective;
use InvalidArgumentException;

/**
 * La correccion de la fecha de cese de un periodo con baja efectiva (RN-27,
 * RN-13, RL-04, ADR-062 §7).
 *
 * **Nada se sobrescribe sin rastro**: la correccion conserva la fecha anterior,
 * la nueva y el motivo, y quien la persiste la guarda en
 * `employment_period_corrections` antes de cambiar el periodo. Este objeto
 * decide si la fecha nueva cabe:
 *
 * - no es posterior a hoy (la fecha civil del centro, ya resuelta);
 * - no es anterior a la fecha de alta del periodo;
 * - es anterior a la fecha de alta del periodo siguiente, si lo hay;
 * - y cambia algo.
 *
 * Como la baja (RN-14), **no se compara con el ultimo tramo ni con las
 * ausencias**: adelantar la fecha no borra nada. Las fechas son civiles
 * `Y-m-d` y no hay reloj (regla dura 2).
 */
final readonly class TerminationDateCorrection
{
    public const int REASON_MIN_LENGTH = 10;

    public const int REASON_MAX_LENGTH = 1000;

    private function __construct(
        public EmploymentPeriod $period,
        public string $previousTerminatedOn,
        public string $terminatedOn,
        public string $reason,
        public bool $ofCurrentPeriod,
    ) {}

    /**
     * @param  string  $today  fecha civil del centro, `Y-m-d`
     *
     * @throws OffboardingNotEffective
     * @throws InvalidTerminationCorrection
     * @throws InvalidArgumentException con un motivo fuera de 10 a 1 000 caracteres
     */
    public static function propose(
        EmploymentHistory $history,
        EmploymentPeriod $period,
        string $terminatedOn,
        string $today,
        string $reason,
    ): self {
        if (! $period->offboarded || $period->terminatedOn === null || $period->uuid === null) {
            throw OffboardingNotEffective::forPeriod((string) $period->uuid);
        }

        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            throw new InvalidArgumentException('El motivo de una correccion de la fecha de cese tiene de 10 a 1 000 caracteres (RN-27).');
        }

        self::assertWithinBounds($history, $period, $terminatedOn, $today);

        return new self($period, $period->terminatedOn, $terminatedOn, $reason, $history->isCurrent($period));
    }

    /**
     * @throws InvalidTerminationCorrection
     */
    private static function assertWithinBounds(EmploymentHistory $history, EmploymentPeriod $period, string $terminatedOn, string $today): void
    {
        if ($terminatedOn > $today) {
            throw InvalidTerminationCorrection::afterToday($terminatedOn, $today);
        }

        if ($terminatedOn < $period->hiredOn) {
            throw InvalidTerminationCorrection::beforeHiring($terminatedOn, $period->hiredOn);
        }

        $next = $history->after($period);

        if ($next instanceof EmploymentPeriod && $terminatedOn >= $next->hiredOn) {
            throw InvalidTerminationCorrection::reachesNextPeriod($terminatedOn, $next->hiredOn);
        }

        if ($terminatedOn === $period->terminatedOn) {
            throw InvalidTerminationCorrection::unchanged($terminatedOn);
        }
    }
}
