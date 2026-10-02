<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/**
 * **A quien se le puede anotar un tramo a mano, y de que jornadas** (RN-14,
 * RF-PA-04, 2.2.0).
 *
 * - **En alta**: como hasta ahora, sin limite de fechas por su periodo de
 *   empleo (el limite de futuro lo pone {@see ManualEntryHorizon}).
 * - **Dada de baja**: solo jornadas entre su fecha de alta y su fecha de cese,
 *   ambas incluidas. Es como se completan los dias trabajados que no constaron:
 *   la baja es efectiva al registrarla y corta el fichaje en el acto, asi que un
 *   olvido de los ultimos dias o un fichaje de la cola offline que llego despues
 *   solo pueden entrar por aqui.
 * - **Suspendida**: no. Ni la suspension tiene fecha de fin que acote nada, ni
 *   es la situacion que esta regla resuelve.
 *
 * **Solo el alta manual del panel pasa por aqui.** El escaneo del quiosco no
 * cambia: una persona de baja no ficha (RN-14).
 *
 * Las fechas son civiles y se comparan como `Y-m-d`, que ordena igual como
 * cadena que como fecha. No conoce el reloj (regla dura 2).
 */
final readonly class ManualEntryEligibility
{
    private function __construct(private EmployeeSnapshot $employee) {}

    public static function of(EmployeeSnapshot $employee): self
    {
        return new self($employee);
    }

    /**
     * Si a esta persona se le puede anotar algun tramo. Una baja sin sus dos
     * fechas no se admite: sin ellas no hay periodo que acote nada, y la base no
     * deja que exista (`employees_chk_terminated_has_date`).
     */
    public function admitsEntries(): bool
    {
        if ($this->employee->canClock()) {
            return true;
        }

        return $this->employee->status === EmploymentStatus::TERMINATED
            && $this->employee->hiredOn !== null
            && $this->employee->terminatedOn !== null;
    }

    /**
     * La jornada declarada, para una persona de baja, cae entre su alta y su
     * cese, ambos incluidos. Para cualquier otra no comprueba nada.
     *
     * @throws WorkDateOutsideEmployment
     */
    public function assertWorkDateAllowed(WorkDate $workDate): void
    {
        $hiredOn = $this->employee->hiredOn;
        $terminatedOn = $this->employee->terminatedOn;

        if ($this->employee->status !== EmploymentStatus::TERMINATED || $hiredOn === null || $terminatedOn === null) {
            return;
        }

        if ($workDate->isoDate < $hiredOn || $workDate->isoDate > $terminatedOn) {
            throw WorkDateOutsideEmployment::forPeriod($workDate->isoDate, $hiredOn, $terminatedOn);
        }
    }
}
