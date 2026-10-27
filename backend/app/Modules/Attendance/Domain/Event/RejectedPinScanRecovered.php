<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Todos los PIN rechazados de una jornada estan subsanados** (RN-19).
 *
 * Lo emite la revision diaria cuando la jornada entera de esa persona queda
 * subsanada. Lo normal es que no hubiera nada que cerrar —la revision espera a
 * que se cierre la ventana antes de abrir la incidencia—; el caso que lo
 * justifica es la subsanacion que **llega tarde**: el fichaje bueno estaba en la
 * cola offline de otro quiosco cuando la revision abrio la incidencia
 * (R3-BE-02 de la re-verificacion 2.2.0).
 *
 * **Attendance no cierra incidencias**: dice que la jornada esta subsanada y
 * `Compliance`, que es quien las tiene, cierra la que siga abierta y deja el
 * asiento (regla dura 6). Por eso, igual que {@see AttendanceAnomalyDetected},
 * los accesores devuelven escalares: el listener no puede nombrar `WorkDate`.
 *
 * Sin datos personales (regla dura 21): la persona por su UUID.
 */
final readonly class RejectedPinScanRecovered implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        /** El instante de la pasada que lo vio, en UTC. */
        public DateTimeImmutable $detectedAt,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una jornada subsanada de RN-19 necesita el UUID de la persona.');
        }

        TimeRange::assertUtc('detectedAt', $detectedAt);
    }

    /** Identificador publico de la persona. **Nunca su nombre** (regla dura 21). */
    public function employeeUuid(): string
    {
        return $this->employeeUuid;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    /** La jornada subsanada, en `Y-m-d` y ya resuelta en la zona del centro (RN-05). */
    public function workDate(): string
    {
        return $this->workDate->isoDate;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.rejected_pin_scan_recovered';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
