<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
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
 *
 * **Lleva la accion que lo abrio** ({@see ClockingAction}: `clock_in` o
 * `break_end`). No es redundante con `scan_events.result`: esa tabla NO esta
 * protegida como `audit_log` —no es solo-append ni encadenada por hash—, y el
 * asiento `shift_entry.created` es la copia inmutable del hecho. Sin este campo,
 * la unica prueba de que un tramo se abrio volviendo de una pausa vive en una
 * tabla que se puede modificar (RL-04, ADR-024).
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
        /**
         * Que abrio el tramo: `CLOCK_IN` o `BREAK_END` (RF-AT-12, ADR-024).
         *
         * Valor por defecto para no romper las correcciones (RF-PA-04) ni las
         * altas manuales, que abren tramo sin que haya habido pausa ninguna.
         */
        public ClockingAction $action = ClockingAction::CLOCK_IN,
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
