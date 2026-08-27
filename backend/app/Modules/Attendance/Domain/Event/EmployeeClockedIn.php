<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha abierto un tramo (RF-AT-02).
 *
 * Lo emite el agregado `WorkDay`, no el caso de uso: quien decide que un
 * escaneo abre turno es el dominio. Attendance **no llama** a Compliance ni a
 * Reporting; emite, y ellos reaccionan (doc 02 §1.6). De aqui salen el panel en
 * vivo (RF-PA-01), la entrada de auditoria y las metricas de negocio.
 *
 * Identifica al empleado por `employeeUuid` y **no lleva su nombre** (regla
 * dura 21): el evento se serializa en logs y en `audit_log.payload`.
 */
final readonly class EmployeeClockedIn implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public string $shiftEntryUuid,
        public WorkDate $workDate,
        /** Momento real de la entrada. En un fichaje offline es el `occurred_at` del dispositivo (regla dura 9). */
        public DateTimeImmutable $clockedInAt,
        public ScanOrigin $origin,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.employee_clocked_in';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->clockedInAt;
    }
}
