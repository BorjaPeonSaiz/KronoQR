<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * RN-05: la jornada es una fecha civil real en la zona del centro.
 *
 * «2026-02-30» y «2026-13-01» no lo son. Se rechaza al reconstruir desde la
 * base de datos igual que al construir, porque una jornada con fecha imposible
 * corrompe el indice de `daily_totals` y la exportacion legal.
 */
final class InvalidWorkDate extends AttendanceDomainException
{
    public static function notACivilDate(string $value): self
    {
        return new self('"'.$value.'" is not a civil date in YYYY-MM-DD form (RN-05).');
    }
}
