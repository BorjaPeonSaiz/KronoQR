<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Application\Exception\EmployeeCannotBeClocked;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;

/**
 * **La ficha se vuelve a mirar con la cadena de `audit_log` ya tomada** (RN-14,
 * RN-05, ADR-046), para el alta y para la correccion manuales.
 *
 * La instantanea que leen los casos de uso se lee sin candado: una baja que
 * confirmara entre esa lectura y el asiento, con un cese anterior a la jornada
 * —o en la jornada misma, con marcas que pasan de su dia de cese—, dejaria
 * horas posteriores al cese. No se toma la cadena al principio —el alta inserta
 * en `shift_entries`, que comprueba su clave ajena sobre el centro, y eso seria
 * pedir una fila padre con la cadena en la mano (ADR-046 §1.1, punto 2)—: se
 * toma al final, como el fichaje. Con ella tomada, la baja o ya confirmo —y
 * esta lectura la ve— o confirmara despues, y entonces el tramo es anterior a
 * ella en la cadena. Si ya no vale, la excepcion deshace la transaccion entera:
 * ni tramo, ni correccion, ni asiento, ni `daily_totals`.
 *
 * `withChainLock()` es reentrante: si el asiento ya la tomo, no espera.
 *
 * **Un solo sitio para las dos** (2.3.0): la correccion no la hacia, y una baja
 * concurrente dejaba corregir un tramo hasta tres dias despues del cese.
 */
final readonly class ManualEntryRecheck
{
    public function __construct(
        private EmployeeDirectory $employees,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @param  bool  $mustAdmitEntries  el alta exige que la persona admita tramos nuevos (en alta o de baja
     *                                  con fechas); la correccion no depende de su estado (RF-PA-04)
     * @param  ShiftEntryTimes|null  $previous  en una correccion, las marcas anteriores: solo se miran las que cambian
     *
     * @throws EmployeeCannotBeClocked
     * @throws WorkDateOutsideEmployment
     * @throws ShiftEntryMarkAfterTermination
     */
    public function afterAudit(
        string $employeeUuid,
        WorkDate $workDate,
        ShiftEntryTimes $times,
        ContinuationCeiling $continuation,
        bool $mustAdmitEntries,
        ?ShiftEntryTimes $previous = null,
    ): void {
        $this->serialized->withChainLock(function () use ($employeeUuid, $workDate, $times, $continuation, $mustAdmitEntries, $previous): void {
            $current = $this->employees->find($employeeUuid);

            if (! $current instanceof EmployeeSnapshot) {
                if ($mustAdmitEntries) {
                    throw EmployeeCannotBeClocked::withUuid($employeeUuid);
                }

                return;
            }

            self::assertAllowed($current, $workDate, $times, $continuation, $mustAdmitEntries, $previous);
        });
    }

    /**
     * Las mismas comprobaciones, sobre una instantanea ya leida: la primera
     * lectura de los casos de uso y la relectura hacen exactamente lo mismo.
     *
     * @throws EmployeeCannotBeClocked
     * @throws WorkDateOutsideEmployment
     * @throws ShiftEntryMarkAfterTermination
     */
    public static function assertAllowed(
        EmployeeSnapshot $employee,
        WorkDate $workDate,
        ShiftEntryTimes $times,
        ContinuationCeiling $continuation,
        bool $mustAdmitEntries,
        ?ShiftEntryTimes $previous = null,
    ): void {
        $eligibility = ManualEntryEligibility::of($employee);

        if ($mustAdmitEntries && ! $eligibility->admitsEntries()) {
            throw EmployeeCannotBeClocked::withUuid($employee->employeeUuid);
        }

        $eligibility->assertWorkDateAllowed($workDate);
        $eligibility->assertTimesAllowed($times, $continuation, $workDate->timezone, $previous);
    }
}
