<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El total de la jornada se ha **recalculado** (RN-06, ADR-007).
 *
 * Recalculado, no incrementado: el evento transporta el estado completo de la
 * proyeccion, no un delta. Es lo que la hace idempotente y correcta ante una
 * correccion —donde el total puede **bajar**— y lo que permite reconstruir
 * `daily_totals` desde cero en cualquier momento sin arrastrar el valor
 * anterior. Un evento con «suma 240 minutos» no tendria esa propiedad.
 *
 * Sus campos son los de la tabla `daily_totals` a proposito: quien proyecta
 * escribe lo que recibe, y no tiene que consultar nada mas para hacerlo.
 */
final readonly class DailyTotalsRecalculated implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public WorkDate $workDate,
        /** Suma de los tramos vigentes de la jornada. */
        public WorkedDuration $total,
        public int $shiftCount,
        public ?DateTimeImmutable $firstClockInAt,
        /** Nulo mientras la jornada tenga un tramo abierto. */
        public ?DateTimeImmutable $lastClockOutAt,
        public bool $hasOpenShift,
        /** Si algun tramo de la jornada quedo marcado para revision (RN-07, RN-08). */
        public bool $hasAnomaly,
        /** Instante del fichaje que provoco el recalculo, no el de la escritura. */
        public DateTimeImmutable $recalculatedAt,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.daily_totals_recalculated';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->recalculatedAt;
    }
}
