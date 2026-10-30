<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Un tramo escrito a mano cae en una jornada fuera del periodo de empleo: para
 * una persona **dada de baja**, posterior a su fecha de cese o anterior a su
 * fecha de alta (RN-14, RF-PA-04, 2.2.0); para una con la **baja programada**,
 * posterior a su fecha de cese (2.3.0).
 *
 * Tras la baja se pueden completar a mano los dias trabajados hasta el cese
 * —un olvido de los ultimos dias, un fichaje de la cola offline que llego
 * tarde—; fuera de ese periodo, esas horas serian de alguien que no trabajaba
 * en el hotel.
 *
 * Mismo criterio que {@see ShiftEntryMarkInFuture}: `getMessage()` es tecnico y en
 * ingles —va al log, **sin datos personales** (regla dura 21)—; lo que ve una
 * persona sale de {@see self::$translationKey} y {@see self::$parameters}, que
 * el borde HTTP cuelga de `errors.work_date` en el idioma negociado. Solo la
 * lanza el alta manual: el escaneo no pasa por aqui.
 */
final class WorkDateOutsideEmployment extends AttendanceDomainException implements BusinessRejection
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

    public static function forPeriod(string $workDate, string $hiredOn, string $terminatedOn): self
    {
        return new self(
            'attendance.errors.work_date_outside_employment',
            ['hired_on' => $hiredOn, 'terminated_on' => $terminatedOn],
            sprintf(
                'The work date %s is outside the employment period %s..%s of an offboarded employee (RN-14).',
                $workDate,
                $hiredOn,
                $terminatedOn,
            ),
        );
    }

    /**
     * La jornada cae en el **hueco entre dos periodos de alta** de una persona
     * readmitida (RN-27): despues del cese de uno y antes del alta del
     * siguiente. Ese dia no trabajaba en el hotel.
     */
    public static function betweenPeriods(string $workDate, string $terminatedOn, string $rehiredOn): self
    {
        return new self(
            'attendance.errors.work_date_between_periods',
            ['terminated_on' => $terminatedOn, 'rehired_on' => $rehiredOn],
            sprintf(
                'The work date %s falls between the termination on %s and the rehiring on %s (RN-27).',
                $workDate,
                $terminatedOn,
                $rehiredOn,
            ),
        );
    }

    /**
     * Una persona con la **baja programada** (en alta, con fecha de cese): la
     * jornada es posterior a su cese (RN-14, 2.3.0). Desde que hay fecha de cese
     * ningun tramo nuevo cae despues, aunque la baja aun no se haya aplicado.
     */
    public static function afterTermination(string $workDate, string $terminatedOn): self
    {
        return new self(
            'attendance.errors.work_date_after_termination',
            ['terminated_on' => $terminatedOn],
            sprintf(
                'The work date %s is after the termination date %s of a scheduled offboarding (RN-14).',
                $workDate,
                $terminatedOn,
            ),
        );
    }
}
