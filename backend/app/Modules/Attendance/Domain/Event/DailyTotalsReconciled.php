<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La proyeccion `daily_totals` de una jornada **no coincidia** con sus eventos
 * origen y se ha reescrito (RF-PR-02, ADR-007, tarea 2.7).
 *
 * **No es lo mismo que {@see DailyTotalsRecalculated}, y la diferencia es todo
 * el sentido de este evento.** Aquel ocurre en cada fichaje y describe lo
 * normal: el dia ha cambiado, la proyeccion se reescribe. Este ocurre cuando la
 * proyeccion decia algo que los tramos vigentes no dicen, que es un **incidente
 * de integridad** (doc 02 §8.2: `projection_divergence_total` debe permanecer
 * siempre en cero). Auditar el primero llenaria `audit_log` de ruido a razon de
 * un asiento por escaneo; auditar el segundo es la regla dura 6: si la
 * reconciliacion corrige un agregado, la correccion queda trazada con el valor
 * anterior y el nuevo.
 *
 * **Attendance no escribe `audit_log`**: emite el hecho y `Compliance` lo sella
 * (doc 02 §1.6). Es la misma via que usan `EmployeeClockedIn` y
 * `AttendanceAnomalyDetected`, y la unica que no obliga al nucleo a conocer la
 * auditoria.
 *
 * **Los accesores devuelven escalares a proposito**: `Compliance` solo puede
 * alcanzar `Attendance\Domain\Event` (§1.6, verificado por Deptrac), asi que el
 * listener no puede nombrar ningun objeto de valor de este modulo.
 *
 * **Nunca lleva nombres** (regla dura 21): el sujeto es `employee_uuid`.
 */
final readonly class DailyTotalsReconciled implements DomainEvent
{
    /**
     * @param  list<string>  $divergentFields  columnas de `daily_totals` que no coincidian
     */
    public function __construct(
        public string $employeeUuid,
        public WorkDate $workDate,
        public array $divergentFields,
        /** No habia fila: la jornada existia en `shift_entries` y no en la proyeccion. */
        public bool $rowWasMissing,
        /** Lo que la proyeccion afirmaba. Nulo si no habia fila. */
        public ?int $previousTotalMinutes,
        public ?int $previousShiftCount,
        /** Lo que dicen los tramos vigentes, que es lo que se ha escrito. */
        public int $totalMinutes,
        public int $shiftCount,
        public DateTimeImmutable $reconciledAt,
    ) {}

    /** La jornada afectada, en `Y-m-d` y ya resuelta en la zona del centro (RN-05). */
    public function workDateIso(): string
    {
        return $this->workDate->isoDate;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.daily_totals_reconciled';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->reconciledAt;
    }
}
