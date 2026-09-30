<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use DateTimeImmutable;

/**
 * Un tramo escrito a mano —alta o correccion— trae una marca o una jornada
 * posteriores a la hora del servidor mas el margen configurado (F1, RL-01,
 * RL-04).
 *
 * Un registro horario anota lo que ya ha ocurrido. Admitir el futuro dejaba
 * rellenar la jornada teorica por adelantado o cerrar un turno con la salida
 * «prevista», y esas horas valen para la nomina igual que las fichadas.
 *
 * Mismo criterio que `InvalidComplianceProfileValue`: `getMessage()` es tecnico
 * y en ingles —va al log y a la traza, **sin datos personales** (regla dura
 * 21)—; lo que ve una persona sale de {@see self::$translationKey} y
 * {@see self::$parameters}, que el borde HTTP resuelve en el idioma negociado.
 * **Nunca llega al quiosco**: ese camino no pasa por esta politica (regla dura
 * 19).
 */
final class ShiftMarkInFuture extends AttendanceDomainException
{
    /**
     * @param  array<string, int>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly FutureMark $mark,
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function instant(
        FutureMark $mark,
        DateTimeImmutable $instant,
        DateTimeImmutable $latestAcceptable,
        int $toleranceMinutes,
    ): self {
        return new self(
            $mark,
            'attendance.errors.mark_in_future',
            ['minutes' => $toleranceMinutes],
            sprintf(
                'The %s mark %s is later than the latest acceptable instant %s (F1, RL-04).',
                $mark->value,
                $instant->format(DateTimeImmutable::ATOM),
                $latestAcceptable->format(DateTimeImmutable::ATOM),
            ),
        );
    }

    public static function workDate(string $workDate, string $latestAcceptableDate): self
    {
        return new self(
            FutureMark::WorkDate,
            'attendance.errors.work_date_in_future',
            [],
            sprintf(
                'The work date %s is later than the latest acceptable civil date %s (F1, RL-04).',
                $workDate,
                $latestAcceptableDate,
            ),
        );
    }
}
