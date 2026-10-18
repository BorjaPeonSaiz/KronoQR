<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Exception\EntryDoesNotContinueWorkDay;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;

/**
 * **RN-05 frente a las otras jornadas de la persona** (2.3.0).
 *
 * `WorkDay` comprueba la cota con sus propios tramos, y no ve los de otras
 * jornadas. Si entre la salida anterior y la entrada de un tramo de otro dia
 * civil hay un tramo vigente de **otra** jornada —la persona ficho en el
 * quiosco entre medias y eso abrio la jornada del dia siguiente—, la jornada ya
 * se interrumpio: el tramo no la continua aunque quede dentro de la cota.
 *
 * El hueco lo da el agregado (`continuationGapFor()`,
 * `continuationGapAfterVoiding()`) y la pregunta la contesta el repositorio,
 * dentro de la transaccion del caso de uso.
 */
final readonly class ContinuationAcrossWorkDays
{
    /**
     * @param  bool  $isWritten  el tramo que no continua es el escrito (alta, correccion) o el siguiente (anulacion)
     *
     * @throws EntryDoesNotContinueWorkDay
     */
    public static function assertUninterrupted(
        WorkDayRepository $workDays,
        WorkDay $workDay,
        ?TimeRange $gap,
        ContinuationCeiling $continuation,
        bool $isWritten = true,
    ): void {
        if (! $gap instanceof TimeRange) {
            return;
        }

        if (! $workDays->hasEntryOfAnotherWorkDayWithin($workDay->employeeUuid(), $workDay->workDate(), $gap)) {
            return;
        }

        throw EntryDoesNotContinueWorkDay::interruptedByAnotherWorkDay(
            FutureMark::ClockIn,
            $workDay->workDate()->isoDate,
            $gap->start,
            $gap->end,
            $continuation,
            $isWritten,
        );
    }
}
