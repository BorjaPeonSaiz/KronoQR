<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Support\ContinuationCeilings;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\FinalWorkDayActivity;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Si la jornada del cese de una persona puede seguir creciendo (RN-14, RN-05,
 * ADR-062 §4): lo que necesita saber la tarea que aplica las bajas programadas
 * para no cortar el turno de noche ni la pausa de madrugada del ultimo dia.
 *
 * Dos hechos, los dos del registro horario y con la cota de este modulo:
 *
 * 1. **Un tramo vigente sin salida** de una jornada hasta el cese. Se pregunta
 *    a la tabla **sin bloquear**, y no con `WorkDayRepository::findOpenWorkDayFor()`:
 *    aquel toma la fila del tramo con `FOR NO KEY UPDATE`, y aqui se llega con
 *    la cadena de auditoria ya tomada. El fichaje toma el tramo y despues la
 *    cadena; tomarlos al reves seria el abrazo mortal de ADR-046 §1, y la
 *    victima podria ser el fichaje.
 * 2. **Una pausa en curso** de una jornada hasta el cese —el ultimo escaneo
 *    aceptado fue «Pausa»— cuya vuelta todavia cabe en `$at`: por debajo de la
 *    cota de continuacion del perfil del centro (RN-10, regla dura 14) y antes
 *    del final del dia siguiente a esa jornada, el tope de RN-05. Son las mismas
 *    dos condiciones con las que `ScanIntentPolicy` decide si un escaneo vuelve
 *    de una pausa.
 *
 * Si la pausa la retiro despues una correccion, esto todavia la ve y la tarea
 * aplaza de mas; el tope de la tarea lo acota. Al reves no puede equivocarse:
 * nunca dice «no crece» de una jornada que si puede crecer.
 */
final readonly class DatabaseFinalWorkDayActivity implements FinalWorkDayActivity
{
    public function __construct(
        private ConnectionInterface $connection,
        private ScanLog $scans,
        private CompliancePolicyProvider $compliance,
    ) {}

    public function mayStillGrow(string $employeeUuid, int $siteId, string $terminatedOn, DateTimeImmutable $at): bool
    {
        return $this->hasOpenEntryUntil($employeeUuid, $terminatedOn)
            || $this->hasBreakThatMayContinue($employeeUuid, $siteId, $terminatedOn, $at);
    }

    private function hasOpenEntryUntil(string $employeeUuid, string $terminatedOn): bool
    {
        // El indice unico parcial `one_open_shift_per_employee` sirve esta
        // consulta: como mucho una fila por persona.
        return $this->connection->table('shift_entries')
            ->join('employees', 'employees.id', '=', 'shift_entries.employee_id')
            ->where('employees.uuid', $employeeUuid)
            ->whereNull('shift_entries.clocked_out_at')
            ->whereNotIn('shift_entries.status', [ShiftEntryStatus::VOIDED->value, ShiftEntryStatus::SUPERSEDED->value])
            ->where('shift_entries.work_date', '<=', $terminatedOn)
            ->exists();
    }

    private function hasBreakThatMayContinue(string $employeeUuid, int $siteId, string $terminatedOn, DateTimeImmutable $at): bool
    {
        $last = $this->scans->lastAcceptedScanOf($employeeUuid);

        if (! $last instanceof AcceptedScan || $last->breakInProgressShiftEntry() === null) {
            return false;
        }

        $workDate = $last->workDate;

        if (! $workDate instanceof WorkDate || $workDate->isoDate > $terminatedOn) {
            return false;
        }

        return $at < $workDate->next()->endsAt()
            && ContinuationCeilings::forSite($this->compliance, $siteId)->continues($last->occurredAt, $at);
    }
}
