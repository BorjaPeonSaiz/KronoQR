<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La fecha de cese corregida no cabe en el periodo (RN-27, ADR-062 §7): es
 * posterior a hoy, anterior a la fecha de alta del periodo, alcanza la del
 * periodo siguiente o no cambia nada. `422` colgado de `terminated_at`, con el
 * limite en el mensaje.
 */
final class InvalidTerminationCorrection extends WorkforceDomainException implements BusinessRejection
{
    /**
     * @param  array<string, string>  $parameters
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function afterToday(string $terminatedOn, string $today): self
    {
        return new self(
            'employees.errors.correction_after_today',
            ['terminated_on' => $terminatedOn, 'today' => $today],
            sprintf('The corrected termination date %s is after today %s (RN-27).', $terminatedOn, $today),
        );
    }

    public static function beforeHiring(string $terminatedOn, string $hiredOn): self
    {
        return new self(
            'employees.errors.correction_before_hiring',
            ['terminated_on' => $terminatedOn, 'hired_on' => $hiredOn],
            sprintf('The corrected termination date %s is before the hiring date %s of its period (RN-27).', $terminatedOn, $hiredOn),
        );
    }

    public static function reachesNextPeriod(string $terminatedOn, string $nextHiredOn): self
    {
        return new self(
            'employees.errors.correction_reaches_next_period',
            ['terminated_on' => $terminatedOn, 'next_hired_on' => $nextHiredOn],
            sprintf('The corrected termination date %s reaches the next period, hired on %s (RN-27).', $terminatedOn, $nextHiredOn),
        );
    }

    public static function unchanged(string $terminatedOn): self
    {
        return new self(
            'employees.errors.correction_unchanged',
            ['terminated_on' => $terminatedOn],
            sprintf('The termination date is already %s (RN-27).', $terminatedOn),
        );
    }
}
