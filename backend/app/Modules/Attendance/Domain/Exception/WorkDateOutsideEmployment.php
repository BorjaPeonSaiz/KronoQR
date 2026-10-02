<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Un tramo escrito a mano para una persona **dada de baja** cae en una jornada
 * fuera de su periodo de empleo: posterior a su fecha de cese o anterior a su
 * fecha de alta (RN-14, RF-PA-04, 2.2.0).
 *
 * Tras la baja se pueden completar a mano los dias trabajados hasta el cese
 * —un olvido de los ultimos dias, un fichaje de la cola offline que llego
 * tarde—; fuera de ese periodo, esas horas serian de alguien que no trabajaba
 * en el hotel.
 *
 * Mismo criterio que {@see ShiftMarkInFuture}: `getMessage()` es tecnico y en
 * ingles —va al log, **sin datos personales** (regla dura 21)—; lo que ve una
 * persona sale de {@see self::$translationKey} y {@see self::$parameters}, que
 * el borde HTTP cuelga de `errors.work_date` en el idioma negociado. Solo la
 * lanza el alta manual: el escaneo no pasa por aqui.
 */
final class WorkDateOutsideEmployment extends AttendanceDomainException
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
}
