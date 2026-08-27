<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\CorrectionAction;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use DateTimeImmutable;

/**
 * Como queda el registro despues de una correccion, para quien la pidio.
 *
 * Un resultado tipado y no el agregado: la capa HTTP no debe poder llamar a
 * `correctEntry()` sobre lo que le devuelve un caso de uso.
 *
 * Lleva **los dos identificadores** —el de la version resultante y el de la que
 * queda sustituida— porque el panel necesita los dos: uno para seguir
 * trabajando y otro para enlazar el historico. Es tambien lo que le dice al
 * cliente que el `uuid` que envio en el `PATCH` **ya no es el vigente**: cada
 * correccion crea una fila nueva y `shift_entries.uuid` es unico, asi que la
 * version corregida tiene identificador propio (RN-13, ADR-026).
 */
final readonly class CorrectedShift
{
    public function __construct(
        public string $employeeUuid,
        public string $workDate,
        public CorrectionAction $action,
        /** Version resultante: la corregida, la creada, o la anulada si fue una anulacion. */
        public string $shiftEntryUuid,
        public int $version,
        public ShiftEntryStatus $status,
        public DateTimeImmutable $clockedInAt,
        public ?DateTimeImmutable $clockedOutAt,
        /** La version que deja de ser vigente, si la correccion sustituyo alguna. */
        public ?string $supersededShiftEntryUuid,
        /** Total del dia **recalculado** (RN-06). Puede ser menor que antes. */
        public int $dailyTotalMinutes,
    ) {}

    public static function of(WorkDay $workDay, ShiftEntry $entry, ShiftCorrected $correction): self
    {
        return new self(
            employeeUuid: $workDay->employeeUuid(),
            workDate: $workDay->workDate()->isoDate,
            action: $correction->action,
            shiftEntryUuid: $entry->uuid(),
            version: $entry->version(),
            status: $entry->status(),
            clockedInAt: $entry->clockedInAt(),
            clockedOutAt: $entry->clockedOutAt(),
            supersededShiftEntryUuid: $correction->replacementShiftEntryUuid === null
                ? null
                : $correction->shiftEntryUuid,
            dailyTotalMinutes: $correction->dailyTotal->minutes,
        );
    }
}
