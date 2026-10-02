<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * La fecha de cese no es admisible (RF-GP-03, RN-14).
 *
 * Tres causas, cada una con su constructor y su texto:
 *
 * - **Anterior al alta.** La misma regla esta declarada en la base de datos
 *   (`employees_chk_terminated_after_hired`, tarea 1.3) y aqui. No es
 *   duplicacion inutil: la de la base protege de las escrituras que no pasan
 *   por el dominio, y esta da un error con significado a quien da la baja.
 * - **Posterior a hoy** (2.2.0, decision del propietario de 02-10-2026). La baja
 *   es efectiva al registrarla: una fecha futura dejaria a la persona sin fichar
 *   ni poder anotar su jornada hasta el cese. «Hoy» es la fecha civil del centro
 *   y la resuelve quien llama.
 * - **Alta que no llego a empezar.** Con la fecha de alta aun por llegar, la
 *   unica fecha de cese posible es la del alta: es una baja sin efectos.
 *
 * Mismo criterio que `ShiftMarkInFuture`: `getMessage()` es tecnico y en ingles
 * —va al log, **sin datos personales** (regla dura 21)—; lo que ve una persona
 * sale de {@see self::$translationKey} y {@see self::$parameters}, que el borde
 * HTTP resuelve en el idioma negociado.
 */
final class InvalidEmploymentPeriod extends WorkforceDomainException
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

    public static function terminationAfterToday(string $terminatedOn, string $today): self
    {
        return new self(
            'employees.errors.termination_after_today',
            ['terminated_on' => $terminatedOn, 'today' => $today],
            sprintf('The termination date %s is later than today %s at the site (RN-14).', $terminatedOn, $today),
        );
    }

    public static function notStartedTerminationMustBeHireDate(string $terminatedOn, string $hiredOn): self
    {
        return new self(
            'employees.errors.not_started_termination_must_be_hire_date',
            ['terminated_on' => $terminatedOn, 'hired_on' => $hiredOn],
            sprintf(
                'The hiring date %s has not arrived yet: the only admissible termination date is the hiring date, not %s (RN-14).',
                $hiredOn,
                $terminatedOn,
            ),
        );
    }
}
