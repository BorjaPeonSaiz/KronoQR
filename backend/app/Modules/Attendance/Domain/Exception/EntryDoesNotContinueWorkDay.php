<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
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
 * **Por que no continua**, cada causa con su mensaje (2.3.0): el tramo anterior
 * no termino en pausa ({@see notAfterBreak()}), el tramo cae despues del dia
 * siguiente a la jornada ({@see beyondNextDay()}), la cota del descanso minimo
 * ({@see writtenEntry()}, {@see nextEntry()}) u otra jornada entre medias
 * ({@see interruptedByAnotherWorkDay()}). Para la cota, dos casos:
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
final class EntryDoesNotContinueWorkDay extends AttendanceDomainException implements BusinessRejection
{
    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly FutureMark $mark,
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
        /** Si el que deja de continuar la jornada es el tramo siguiente y no el escrito. */
        public readonly bool $concernsNextEntry,
    ) {
        parent::__construct($message);
    }

    /** El tramo escrito queda a la cota o mas alla de la salida anterior. */
    public static function writtenEntry(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return self::beyondCeiling('attendance.errors.entry_does_not_continue_work_day', true, $mark, $workDate, $lastMark, $start, $ceiling);
    }

    /** El tramo siguiente al escrito, o al anulado, quedaria a la cota o mas alla. */
    public static function nextEntry(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return self::beyondCeiling('attendance.errors.next_entry_would_not_continue_work_day', false, $mark, $workDate, $lastMark, $start, $ceiling);
    }

    /**
     * El tramo anterior no termino en pausa: solo una pausa se continua al dia
     * civil siguiente, igual que en el quiosco (RN-05, 2.3.0).
     */
    public static function notAfterBreak(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $start,
        bool $isWritten,
    ): self {
        return new self(
            $mark,
            'attendance.errors.entry_not_after_break',
            ['work_date' => $workDate],
            sprintf(
                '%s starting at %s does not continue work day %s: the previous entry did not end in a break (RN-05).',
                self::subject($isWritten),
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
            ),
            ! $isWritten,
        );
    }

    /**
     * El tramo empieza despues del dia siguiente a la jornada: el tope de RN-05
     * (2.3.0). Sin el, una cadena de pausas uniria dias sucesivos en una sola
     * jornada y esconderia un descanso insuficiente (RN-10).
     */
    public static function beyondNextDay(
        FutureMark $mark,
        string $workDate,
        string $nextDay,
        DateTimeImmutable $start,
        bool $isWritten,
    ): self {
        return new self(
            $mark,
            'attendance.errors.entry_beyond_next_day',
            ['work_date' => $workDate, 'next_day' => $nextDay],
            sprintf(
                '%s starting at %s does not continue work day %s: a work day only continues until %s (RN-05).',
                self::subject($isWritten),
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $nextDay,
            ),
            ! $isWritten,
        );
    }

    /**
     * El tramo queda dentro de la cota, pero entre la salida anterior y su
     * entrada hay un tramo vigente de otra jornada de la persona: la jornada ya
     * se interrumpio.
     */
    public static function interruptedByAnotherWorkDay(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
        bool $isWritten,
    ): self {
        return new self(
            $mark,
            $isWritten
                ? 'attendance.errors.entry_does_not_continue_work_day'
                : 'attendance.errors.next_entry_would_not_continue_work_day',
            ['work_date' => $workDate, 'hours' => $ceiling->hours()],
            sprintf(
                '%s starting at %s does not continue work day %s: a current shift entry of another work day '
                .'lies between the previous mark %s and it (RN-05).',
                self::subject($isWritten),
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $lastMark->format(DateTimeImmutable::ATOM),
            ),
            ! $isWritten,
        );
    }

    private static function beyondCeiling(
        string $translationKey,
        bool $isWritten,
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        ContinuationCeiling $ceiling,
    ): self {
        return new self(
            $mark,
            $translationKey,
            ['work_date' => $workDate, 'hours' => $ceiling->hours()],
            sprintf(
                '%s starting at %s does not continue work day %s: the previous mark %s is %d minutes '
                .'or more before it (RN-05).',
                self::subject($isWritten),
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $lastMark->format(DateTimeImmutable::ATOM),
                $ceiling->minimumRest->minutes,
            ),
            ! $isWritten,
        );
    }

    private static function subject(bool $isWritten): string
    {
        return $isWritten ? 'A shift entry' : 'The next shift entry';
    }
}
