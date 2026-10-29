<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
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
 * - **Con baja programada** (en alta o suspendida, con fecha de cese; 2.3.0):
 *   lo de su estado, y ninguna jornada posterior al cese. Desde que hay fecha
 *   de cese, ningun tramo nuevo cae despues, la haya aplicado ya la tarea o no.
 *
 * **Y sus marcas, ni mas alla del dia del cese** (RN-05, 2.3.0): con
 * {@see assertTimesAllowed()} ninguna marca de una persona con fecha de cese pasa
 * de la cota de continuacion contada desde el final de su dia de cese. Esta
 * comprobacion la usan tambien las correcciones, que no dependen del estado de la
 * persona salvo en esto.
 *
 * **Solo el camino manual del panel pasa por aqui.** El escaneo del quiosco
 * aplica la misma regla de jornadas con {@see ScanIntentPolicy::opensAfterTermination()}.
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
     * La jornada declarada cae dentro del periodo de empleo (RN-14, RF-PA-04):
     *
     * - **Dada de baja**: entre su alta y su cese, ambos incluidos.
     * - **Con baja programada** (en alta o suspendida, con fecha de cese): nunca
     *   despues del cese (2.3.0). Hacia atras, como cualquier persona en alta.
     * - Sin fecha de cese: no comprueba nada.
     *
     * @throws WorkDateOutsideEmployment
     */
    public function assertWorkDateAllowed(WorkDate $workDate): void
    {
        $hiredOn = $this->employee->hiredOn;
        $terminatedOn = $this->employee->terminatedOn;

        if ($terminatedOn === null) {
            return;
        }

        if ($this->employee->status !== EmploymentStatus::TERMINATED) {
            if ($workDate->isoDate > $terminatedOn) {
                throw WorkDateOutsideEmployment::afterTermination($workDate->isoDate, $terminatedOn);
            }

            return;
        }

        if ($hiredOn === null) {
            return;
        }

        if ($workDate->isoDate < $hiredOn || $workDate->isoDate > $terminatedOn) {
            throw WorkDateOutsideEmployment::forPeriod($workDate->isoDate, $hiredOn, $terminatedOn);
        }
    }

    /**
     * Las marcas de un tramo escrito a mano, para una persona con fecha de cese
     * —de baja o con la baja programada—, no pasan de la cota de continuacion
     * contada desde el final de su dia de cese (RN-05, RN-14, RF-PA-04). Para
     * cualquier otra no comprueba nada.
     *
     * **El final del dia del cese** es la medianoche del dia siguiente en la zona
     * del centro ({@see WorkDate::endsAt()}), y la cota se suma en segundos
     * reales ({@see ContinuationCeiling::limitAfter()}): el turno de noche del
     * ultimo dia y la vuelta de su pausa de madrugada caben, una entrada tres
     * dias despues no. Es la misma cota que acota la vuelta de una pausa en el
     * quiosco, asi que una marca que el quiosco no habria atribuido a la jornada
     * del cese tampoco entra por el panel.
     *
     * **En una correccion solo se miran las marcas que cambian** (`$previous`,
     * las del tramo antes de corregir), igual que la guarda del agregado es
     * local: una marca que ya estaba escrita no impide corregir la otra.
     *
     * @throws ShiftEntryMarkAfterTermination
     */
    public function assertTimesAllowed(
        ShiftEntryTimes $times,
        ContinuationCeiling $continuation,
        DateTimeZone $timezone,
        ?ShiftEntryTimes $previous = null,
    ): void {
        $terminatedOn = $this->employee->terminatedOn;

        if ($terminatedOn === null) {
            return;
        }

        $limit = $continuation->limitAfter(WorkDate::fromIsoDate($terminatedOn, $timezone)->endsAt());

        if (! self::sameInstant($times->clockedInAt, $previous?->clockedInAt)) {
            self::assertMark(FutureMark::ClockIn, $times->clockedInAt, $limit, $terminatedOn);
        }

        if ($times->clockedOutAt instanceof DateTimeImmutable && ! self::sameInstant($times->clockedOutAt, $previous?->clockedOutAt)) {
            self::assertMark(FutureMark::ClockOut, $times->clockedOutAt, $limit, $terminatedOn);
        }
    }

    private static function sameInstant(DateTimeImmutable $mark, ?DateTimeImmutable $previous): bool
    {
        return $previous instanceof DateTimeImmutable && $previous->getTimestamp() === $mark->getTimestamp();
    }

    /**
     * @throws ShiftEntryMarkAfterTermination
     */
    private static function assertMark(FutureMark $mark, DateTimeImmutable $instant, DateTimeImmutable $limit, string $terminatedOn): void
    {
        if ($instant->getTimestamp() < $limit->getTimestamp()) {
            return;
        }

        throw ShiftEntryMarkAfterTermination::instant($mark, $instant, $limit, $terminatedOn);
    }
}
