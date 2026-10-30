<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La fecha de alta de una readmision no es posterior a la fecha de cese del
 * periodo anterior (RN-27, ADR-062 §6): los periodos no se solapan ni comparten
 * dia. `422` colgado de `hired_at`.
 */
final class InvalidRehireDate extends WorkforceDomainException implements BusinessRejection
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

    public static function notAfterTermination(string $hiredOn, string $terminatedOn): self
    {
        return new self(
            'employees.errors.rehire_not_after_termination',
            ['hired_on' => $hiredOn, 'terminated_on' => $terminatedOn],
            sprintf('The rehiring date %s is not after the termination date %s (RN-27).', $hiredOn, $terminatedOn),
        );
    }
}
