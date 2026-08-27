<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cerrado un tramo y se ha calculado su duracion (RF-AT-03).
 *
 * Lleva **las anomalias ya clasificadas** para que Compliance abra la
 * incidencia de RN-07 o RN-08 sin volver a calcular la duracion ni conocer los
 * umbrales: quien tiene la regla es el nucleo, y el evento transporta la
 * conclusion, no los ingredientes.
 *
 * Lleva tambien el total del dia ya recalculado porque el quiosco lo muestra en
 * la confirmacion —«Hasta luego, Lucia — Salida 11:02 · Hoy: 6 h 0 min»
 * (RF-AT-05)— y porque es lo que evita que quien escuche este evento tenga que
 * sumar tramos por su cuenta y se invente una segunda forma de calcular RN-06.
 */
final readonly class EmployeeClockedOut implements DomainEvent
{
    /**
     * @param  list<ShiftAnomaly>  $anomalies  Vacia en el caso normal.
     */
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public string $shiftEntryUuid,
        public WorkDate $workDate,
        public DateTimeImmutable $clockedInAt,
        /** Momento real de la salida. En un fichaje offline es el `occurred_at` del dispositivo (regla dura 9). */
        public DateTimeImmutable $clockedOutAt,
        public ScanOrigin $origin,
        /** Duracion de **este** tramo (RN-09: aritmetica sobre instantes UTC). */
        public WorkedDuration $worked,
        /** Total de la jornada **recalculado** como suma de sus tramos, nunca incrementado (RN-06). */
        public WorkedDuration $dailyTotal,
        public array $anomalies = [],
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.employee_clocked_out';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->clockedOutAt;
    }
}
