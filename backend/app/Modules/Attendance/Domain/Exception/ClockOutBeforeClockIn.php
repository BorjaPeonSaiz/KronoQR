<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use DateTimeImmutable;

/**
 * RN-03: `clocked_out_at` es **estrictamente** posterior a `clocked_in_at`.
 *
 * Estrictamente, no «posterior o igual»: un tramo de duracion cero no es un
 * tramo corto —eso lo cubre RN-07 con la duracion minima computable—, es un par
 * de marcas que no pueden haber ocurrido. La base de datos lo repite con
 * `shift_entries_chk_order` y lo aplica tambien a las versiones historicas.
 */
final class ClockOutBeforeClockIn extends AttendanceDomainException
{
    public static function between(DateTimeImmutable $clockedInAt, DateTimeImmutable $clockedOutAt): self
    {
        return new self(sprintf(
            'A shift entry cannot end at %s, which is not strictly after its start at %s (RN-03).',
            $clockedOutAt->format(DateTimeImmutable::ATOM),
            $clockedInAt->format(DateTimeImmutable::ATOM),
        ));
    }
}
