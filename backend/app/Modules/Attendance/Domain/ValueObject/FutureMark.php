<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\ManualEntryHorizon;

/**
 * Cual de los tres datos de un tramo escrito a mano ha quedado en el futuro
 * (F1, {@see ManualEntryHorizon}).
 *
 * Los valores son los nombres de columna de `shift_entries` y `work_days`, que
 * son tambien los del contrato: quien traduce la excepcion a `422` cuelga el
 * error del campo que la persona tiene que corregir, sin una tabla de
 * equivalencias que mantener.
 */
enum FutureMark: string
{
    case WorkDate = 'work_date';
    case ClockIn = 'clocked_in_at';
    case ClockOut = 'clocked_out_at';
}
