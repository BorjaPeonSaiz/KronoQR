<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La revision diaria ha encontrado algo que tiene que mirar una persona
 * (RF-PR-01, tarea 2.6).
 *
 * **Attendance no abre incidencias**: emite el hallazgo y `Compliance` decide
 * severidad, responsable y estado (doc 01 §5.1, doc 02 §1.6). Es la misma via
 * por la que ya llega el asiento de `audit_log`, y la unica que no obliga al
 * nucleo a conocer la bandeja que trabaja lo que detecta.
 *
 * **Los accesores devuelven escalares a proposito.** `Compliance` solo puede
 * alcanzar `Attendance\Domain\Event` (§1.6, verificado por Deptrac): si el
 * listener tuviera que nombrar `AnomalyType` o `WorkDate` para leer el evento,
 * la frontera se rompería justo aqui. Es la misma tecnica que usa
 * `RecordShiftEntryAudit` con `ShiftAnomaly`.
 *
 * **Se publica DESPUES de confirmar** —a diferencia de los eventos del fichaje—
 * porque la deteccion no es una transaccion de negocio: no hay nada que deshacer
 * si abrir la incidencia falla, y lo que hay que evitar es lo contrario, una
 * incidencia sobre una jornada que no se llego a leer.
 */
final readonly class AttendanceAnomalyDetected implements DomainEvent
{
    public function __construct(public DetectedAnomaly $anomaly) {}

    /** Valor de `incidents.type` (doc 01 §5.5). */
    public function type(): string
    {
        return $this->anomaly->type->value;
    }

    /** Identificador publico del empleado. **Nunca su nombre** (regla dura 21). */
    public function employeeUuid(): string
    {
        return $this->anomaly->employeeUuid;
    }

    public function siteId(): int
    {
        return $this->anomaly->siteId;
    }

    /** La jornada afectada, en `Y-m-d` y ya resuelta en la zona del centro (RN-05). */
    public function workDate(): string
    {
        return $this->anomaly->workDate->isoDate;
    }

    /** El tramo que lo explica, o `null` cuando el hallazgo es de la jornada entera. */
    public function shiftEntryUuid(): ?string
    {
        return $this->anomaly->shiftEntryUuid;
    }

    /**
     * Minutos medidos y umbral aplicado. Sin datos personales.
     *
     * @return array<string, int>
     */
    public function context(): array
    {
        return $this->anomaly->context;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.anomaly_detected';
    }

    /**
     * El momento en que se **detecto**, que no es el de la jornada: una jornada
     * de anteayer revisada esta madrugada produce un hallazgo de esta madrugada.
     * La fecha del hecho viaja aparte, en `workDate()`.
     */
    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->anomaly->detectedAt;
    }
}
