<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\ShiftMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

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
 * **Y sus marcas, ni mas alla del dia del cese** (RN-05, 2.3.0): con
 * {@see assertTimesAllowed()} ninguna marca de una persona de baja pasa de la
 * cota de continuacion contada desde el final de su dia de cese. Esta comprobacion
 * la usan tambien las correcciones, que no dependen del estado de la persona
 * salvo en esto.
 *
 * **Solo el camino manual del panel pasa por aqui.** El escaneo del quiosco no
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

    /**
     * Las marcas de un tramo escrito a mano, para una persona de baja, no pasan
     * de la cota de continuacion contada desde el final de su dia de cese (RN-05,
     * RN-14, RF-PA-04). Para cualquier otra no comprueba nada.
     *
     * **El final del dia del cese** es la medianoche del dia siguiente en la zona
     * del centro, y la cota se suma en segundos reales: el turno de noche del
     * ultimo dia y la vuelta de su pausa de madrugada caben, una entrada tres
     * dias despues no. Es la misma cota que acota la vuelta de una pausa en el
     * quiosco ({@see ContinuationCeiling}), asi que una marca que el quiosco no
     * habria atribuido a la jornada del cese tampoco entra por el panel.
     *
     * @throws ShiftMarkAfterTermination
     */
    public function assertTimesAllowed(ShiftTimes $times, ContinuationCeiling $continuation, DateTimeZone $timezone): void
    {
        $terminatedOn = $this->employee->terminatedOn;

        if ($this->employee->status !== EmploymentStatus::TERMINATED || $terminatedOn === null) {
            return;
        }

        $endOfTerminationDay = $this->endOfDay($terminatedOn, $timezone);

        $this->assertMark(FutureMark::ClockIn, $times->clockedInAt, $endOfTerminationDay, $continuation, $terminatedOn);

        if ($times->clockedOutAt instanceof DateTimeImmutable) {
            $this->assertMark(FutureMark::ClockOut, $times->clockedOutAt, $endOfTerminationDay, $continuation, $terminatedOn);
        }
    }

    /**
     * @throws ShiftMarkAfterTermination
     */
    private function assertMark(
        FutureMark $mark,
        DateTimeImmutable $instant,
        DateTimeImmutable $endOfTerminationDay,
        ContinuationCeiling $continuation,
        string $terminatedOn,
    ): void {
        if ($continuation->continues($endOfTerminationDay, $instant)) {
            return;
        }

        throw ShiftMarkAfterTermination::instant(
            $mark,
            $instant,
            $endOfTerminationDay->add(new DateInterval('PT'.$continuation->minimumRest->minutes.'M')),
            $terminatedOn,
        );
    }

    /**
     * La medianoche que cierra el dia civil `$isoDate` en la zona del centro, en
     * UTC. Es un instante explicito, no el reloj (regla dura 2): el dia siguiente
     * se calcula sobre la fecha civil, a mediodia UTC para que ningun desfase lo
     * cambie, y `WorkDate::startsAt()` resuelve el cambio de hora (RN-09).
     */
    private function endOfDay(string $isoDate, DateTimeZone $timezone): DateTimeImmutable
    {
        $nextDay = new DateTimeImmutable($isoDate.' 12:00:00', new DateTimeZone('UTC'))
            ->add(new DateInterval('P1D'))
            ->format('Y-m-d');

        return WorkDate::fromIsoDate($nextDay, $timezone)->startsAt();
    }
}
