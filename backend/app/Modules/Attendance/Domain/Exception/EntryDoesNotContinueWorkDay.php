<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use DateTimeImmutable;

/**
 * Un tramo escrito a mano que empieza en otro dia civil **no continua** la
 * jornada: entre la salida del tramo anterior y su entrada median el descanso
 * minimo entre jornadas o mas (RN-05, RF-PA-04, regla dura 4).
 *
 * **Que esta pasando.** Un tramo que no abre la jornada puede empezar despues de
 * la medianoche —la vuelta de una pausa a las 02:30 es de la jornada que empezo
 * ayer a las 22:00—, pero solo si la continua. Sin esta cota, un responsable
 * podia dar de alta en la jornada del 10 un tramo del 13, y esas horas
 * contaban en el total de otro dia; para una persona de baja, ademas, horas
 * posteriores a su cese.
 *
 * `$mark` dice que marca hay que corregir: `clocked_in_at` si el tramo escrito
 * no continua al anterior, `clocked_out_at` si lo que se rompe es la vuelta
 * posterior al acortar la salida. Reutiliza {@see FutureMark} porque sus valores
 * son los nombres de las columnas, que son tambien los del contrato.
 *
 * Mismo criterio que {@see ShiftMarkInFuture}: `getMessage()` es tecnico y en
 * ingles —va al log, **sin datos personales** (regla dura 21)—; lo que ve una
 * persona sale de {@see self::$translationKey} y {@see self::$parameters}.
 */
final class EntryDoesNotContinueWorkDay extends AttendanceDomainException
{
    /**
     * @param  array<string, string|int>  $parameters  sustituciones del mensaje traducido
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

    public static function after(
        FutureMark $mark,
        string $workDate,
        DateTimeImmutable $lastMark,
        DateTimeImmutable $start,
        int $minimumRestMinutes,
    ): self {
        return new self(
            $mark,
            $workDate,
            $lastMark,
            $minimumRestMinutes,
            'attendance.errors.entry_does_not_continue_work_day',
            ['work_date' => $workDate, 'minutes' => $minimumRestMinutes],
            sprintf(
                'A shift entry starting at %s does not continue work day %s: the previous mark %s is %d minutes '
                .'or more before it (RN-05).',
                $start->format(DateTimeImmutable::ATOM),
                $workDate,
                $lastMark->format(DateTimeImmutable::ATOM),
                $minimumRestMinutes,
            ),
        );
    }
}
