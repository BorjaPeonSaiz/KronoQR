<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La fecha de cese no es admisible: es anterior a la fecha de alta (RF-GP-03,
 * RN-14).
 *
 * La misma regla esta declarada en la base de datos
 * (`employees_chk_terminated_after_hired`) y aqui. No es duplicacion inutil: la
 * de la base protege de las escrituras que no pasan por el dominio, y esta da un
 * error con significado a quien da la baja.
 *
 * Una fecha posterior a hoy ya no es un error: programa la baja (RN-14). Lo que
 * no se admite es «Aplicar ya» con ella, y eso es {@see ImmediateOffboardingNotDue},
 * que cuelga de otro campo.
 *
 * Mismo criterio que `ShiftEntryMarkInFuture`: `getMessage()` es tecnico y en ingles
 * —va al log, **sin datos personales** (regla dura 21)—; lo que ve una persona
 * sale de {@see self::$translationKey} y {@see self::$parameters}, que el borde
 * HTTP resuelve en el idioma negociado.
 */
final class InvalidEmploymentPeriod extends WorkforceDomainException implements BusinessRejection
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

    public static function terminationBeforeHiring(string $terminatedOn, string $hiredOn): self
    {
        return new self(
            'employees.errors.termination_before_hiring',
            ['terminated_on' => $terminatedOn, 'hired_on' => $hiredOn],
            sprintf('The termination date %s is earlier than the hiring date %s (RF-GP-03).', $terminatedOn, $hiredOn),
        );
    }
}
