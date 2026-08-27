<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionAction;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Una persona autorizada ha rectificado el registro horario de otra (RF-PA-04,
 * RN-13, RL-04).
 *
 * **Un solo evento para las cuatro acciones** —alta manual, cambio de hora,
 * cierre de un turno olvidado y anulacion— porque legalmente son el mismo hecho:
 * el registro que se defiende ante Inspeccion ya no es el que produjo el
 * quiosco, y hay una persona que responde de ello. Quien escucha distingue por
 * `action`, que es un enum cerrado; cuatro eventos distintos obligarian a cuatro
 * listeners que escriben la misma fila.
 *
 * **Lleva el antes y el despues completos.** No un delta ni «cambio la salida»:
 * las marcas enteras de cada version, que es lo que `shift_corrections.before` y
 * `.after` guardan en JSONB y lo que permite reconstruir el historico sin volver
 * a consultar la tabla de tramos. Cuando falta uno de los dos es porque no
 * existe: en un alta no hay `before`, y en una anulacion no hay `after`.
 *
 * **Quien lo escucha.** `Compliance` escribe el asiento en `audit_log` —regla
 * dura 6, la misma via que `EmployeeClockedIn` y `EmployeeClockedOut`, porque
 * `Attendance` no puede importar `Compliance` (doc 02 §1.6)— y abre incidencia
 * si la version corregida trae anomalias (RN-07, RN-08). `Reporting` reproyecta.
 * El nucleo no sabe que ninguno de los dos existe.
 *
 * **`occurredAt()` es el momento de la CORRECCION, no el del trabajo.** Son dos
 * fechas distintas y las dos viajan: el asiento de auditoria se fecha cuando se
 * rectifico, y las horas trabajadas van dentro, en `before` y `after`. Un tramo
 * de hace tres semanas corregido hoy produce un asiento de hoy.
 */
final readonly class ShiftCorrected implements DomainEvent
{
    /**
     * @param  list<ShiftAnomaly>  $anomalies  Lo que tiene de rara la version resultante (RN-07, RN-08). Vacia en el caso normal.
     */
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        public CorrectionAction $action,
        /** El tramo sobre el que se actuo: la version que queda `superseded`, la anulada, o la creada en un alta. */
        public string $shiftEntryUuid,
        public int $shiftEntryVersion,
        /** La version nueva, cuando la accion crea una. Nula en el alta y en la anulacion. */
        public ?string $replacementShiftEntryUuid,
        public ?int $replacementVersion,
        /** Marcas anteriores. Nulas en un alta manual: antes no habia tramo. */
        public ?ShiftTimes $before,
        /** Marcas resultantes. Nulas en una anulacion: el tramo no ocurrio. */
        public ?ShiftTimes $after,
        /** Autor, momento y motivo. Los tres que RN-13 exige, juntos. */
        public Correction $correction,
        /** Total del dia **recalculado** tras la correccion, nunca incrementado (RN-06, regla dura 7). */
        public WorkedDuration $dailyTotal,
        public array $anomalies = [],
    ) {}

    /**
     * El tramo al que apunta la fila de `shift_corrections`: la version que la
     * accion **produjo**, o la que **termino** si fue una anulacion.
     *
     * Se resuelve aqui y no en el adaptador para que los cuatro casos se decidan
     * en un solo sitio: sin esto, quien escriba la fila tiene que acordarse de
     * que en una correccion el identificador bueno es el de la version nueva y
     * en una anulacion el de la vieja.
     */
    public function correctedShiftEntryUuid(): string
    {
        return $this->replacementShiftEntryUuid ?? $this->shiftEntryUuid;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.shift_corrected';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->correction->performedAt;
    }
}
