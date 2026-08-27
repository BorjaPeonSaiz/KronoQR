<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * El agregado se niega a cargar un tramo que no es suyo.
 *
 * Tres formas de no serlo, y las tres se comprueban al reconstruir desde la
 * base de datos, que es por donde entra un dato mal montado:
 *
 * - de otro empleado;
 * - de otra jornada —recordando que `work_date` es propiedad de la **jornada**
 *   y los tramos la heredan, no la derivan de su propio `clocked_in_at`
 *   (RN-05, ADR-006, ADR-024)—;
 * - ya no vigente: un tramo `voided` o `superseded` es historico y el agregado
 *   no protege invariantes sobre hechos ya sustituidos (ADR-026). Cargarlo
 *   duplicaria los minutos del dia en el recalculo de RN-06.
 */
final class ShiftEntryDoesNotBelongToWorkDay extends AttendanceDomainException
{
    public static function becauseOfEmployee(string $shiftEntryUuid, string $expectedEmployeeUuid): self
    {
        return new self('Shift entry '.$shiftEntryUuid.' does not belong to employee '.$expectedEmployeeUuid.'.');
    }

    public static function becauseOfWorkDate(string $shiftEntryUuid, string $expectedWorkDate): self
    {
        return new self('Shift entry '.$shiftEntryUuid.' is not attributed to work day '.$expectedWorkDate.' (RN-05).');
    }

    public static function becauseItIsNotCurrent(string $shiftEntryUuid, string $status): self
    {
        return new self('Shift entry '.$shiftEntryUuid.' is "'.$status.'" and no longer current, so it is history and not part of the work day (ADR-026).');
    }
}
