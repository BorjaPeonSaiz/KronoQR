<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use DateTimeImmutable;

/**
 * Una marca escrita a mano para una persona **dada de baja** queda a la cota de
 * continuacion o mas alla del final de su dia de cese (RN-05, RN-14, RF-PA-04).
 *
 * La jornada del cese puede acabar despues de la medianoche —el turno de noche
 * del ultimo dia, la vuelta de su pausa de madrugada—, pero no mas alla de lo
 * que RN-05 deja continuar una jornada: el descanso minimo entre jornadas
 * contado desde el final del dia del cese. Una marca posterior seria trabajo de
 * otra jornada, y esa persona ya no trabajaba en el hotel.
 *
 * Mismo criterio que {@see ShiftMarkInFuture}: `getMessage()` es tecnico y en
 * ingles, **sin datos personales** (regla dura 21); el texto de la persona sale
 * de {@see self::$translationKey} y {@see self::$parameters}. Solo la lanza el
 * camino manual del panel: el escaneo de una persona de baja ya se rechaza antes.
 */
final class ShiftMarkAfterTermination extends AttendanceDomainException
{
    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
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
        DateTimeImmutable $limit,
        string $terminatedOn,
    ): self {
        return new self(
            $mark,
            'attendance.errors.mark_after_termination',
            ['terminated_on' => $terminatedOn],
            sprintf(
                'The %s mark %s is at or after %s, the continuation limit of the termination day %s (RN-05, RN-14).',
                $mark->value,
                $instant->format(DateTimeImmutable::ATOM),
                $limit->format(DateTimeImmutable::ATOM),
                $terminatedOn,
            ),
        );
    }
}
