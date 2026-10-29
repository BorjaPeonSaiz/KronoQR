<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * «Aplicar ya» con una fecha de cese posterior a hoy (RN-14, ADR-062 §3).
 *
 * La baja en el acto con cese de hoy es el despido con efecto inmediato. Con un
 * cese futuro no hay nada que aplicar todavia: el ultimo dia no ha llegado, y
 * aplicarla dejaria a la persona sin fichar los dias que si trabaja. Se programa
 * sin el indicador, o se pone la fecha de hoy.
 *
 * Mismo criterio que {@see InvalidEmploymentPeriod}: `getMessage()` es tecnico y
 * en ingles —va al log, **sin datos personales** (regla dura 21)—; lo que ve una
 * persona sale de {@see self::$translationKey} y {@see self::$parameters}, que
 * el borde HTTP cuelga de `errors.apply_now`.
 */
final class ImmediateOffboardingNotDue extends WorkforceDomainException implements BusinessRejection
{
    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forTermination(string $terminatedOn, string $today): self
    {
        return new self(
            'employees.errors.apply_now_requires_due_termination',
            ['terminated_on' => $terminatedOn, 'today' => $today],
            sprintf('Apply now needs a termination date of today or earlier, not %s (today is %s at the site, RN-14).', $terminatedOn, $today),
        );
    }
}
