<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use DateTimeImmutable;

/**
 * Un tramo de otro dia civil **no continua** la jornada: entre la salida del
 * tramo anterior y su entrada median el descanso minimo entre jornadas o mas, o
 * hay un tramo de otra jornada de la persona (RN-05, RF-PA-04, regla dura 4).
 *
 * **Que esta pasando.** Un tramo que no abre la jornada puede empezar despues de
 * la medianoche —la vuelta de una pausa a las 02:30 es de la jornada que empezo
 * ayer a las 22:00—, pero solo si la continua. Sin esta cota, un responsable
 * podia dar de alta en la jornada del 10 un tramo del 13, y esas horas
 * contaban en el total de otro dia; para una persona de baja, ademas, horas
 * posteriores a su cese.
 *
 * **Dos casos, dos mensajes** (2.3.0):
 *
 * - {@see writtenEntry()}: el tramo que se esta escribiendo —alta o
 *   correccion— es el que no continua. Su marca es la entrada.
 * - {@see nextEntry()}: lo que se escribe, o lo que se anula, deja al tramo
 *   **siguiente** sin continuar la jornada. La marca es la salida que se
 *   adelanta, o la entrada de ese tramo siguiente cuando el escrito se mueve de
 *   sitio o se anula.
 *
 * `$mark` reutiliza {@see FutureMark} porque sus valores son los nombres de las
 * columnas, que son tambien los del contrato.
 *
 * Mismo criterio que {@see ShiftMarkInFuture}: `getMessage()` es tecnico y en
 * ingles —va al log, **sin datos personales** (regla dura 21)—; lo que ve una
 * persona sale de {@see self::$translationKey} y {@see self::$parameters}, con la
 * cota en horas.
 */
final class EntryDoesNotContinueWorkDay extends AttendanceDomainException
{
    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly FutureMark $mark,
        public readonly string $workDate,
        public readonly DateTimeImmutable $lastMark,
        public readonly int $minimumRestMinutes,
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** El tramo escrito es el que no continua la jornada. */
    public static function writtenEntry(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return self::make('attendance.errors.entry_does_not_continue_work_day', 'A shift entry', $mark, $workDate, $lastMark, $start, $ceiling);
    }

    /** El tramo siguiente al escrito, o al anulado, dejaria de continuar la jornada. */
    public static function nextEntry(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return self::make('attendance.errors.next_entry_would_not_continue_work_day', 'The next shift entry', $mark, $workDate, $lastMark, $start, $ceiling);
    }

    /**
     * El tramo escrito queda dentro de la cota, pero entre la salida anterior y
     * su entrada hay un tramo vigente de otra jornada de la persona: la jornada
     * ya se interrumpio. Mismo mensaje que {@see writtenEntry()}.
     */
    public static function interruptedByAnotherWorkDay(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
        bool $isWritten = true,
    ): self {
        return new self(
            $mark,
            $workDate,
            $lastMark,
            $ceiling->minimumRest->minutes,
            $isWritten
                ? 'attendance.errors.entry_does_not_continue_work_day'
                : 'attendance.errors.next_entry_would_not_continue_work_day',
            ['work_date' => $workDate, 'hours' => $ceiling->hours()],
            sprintf(
                'A shift entry starting at %s does not continue work day %s: a current shift entry of another work day '
                .'lies between the previous mark %s and it (RN-05).',
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $lastMark->format(DateTimeImmutable::ATOM),
            ),
        );
    }

    private static function make(
        string $translationKey,
        string $subject,
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return new self(
            $mark,
            $workDate,
            $lastMark,
            $ceiling->minimumRest->minutes,
            $translationKey,
            ['work_date' => $workDate, 'hours' => $ceiling->hours()],
            sprintf(
                '%s starting at %s does not continue work day %s: the previous mark %s is %d minutes '
                .'or more before it (RN-05).',
                $subject,
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $lastMark->format(DateTimeImmutable::ATOM),
                $ceiling->minimumRest->minutes,
            ),
        );
    }
}
