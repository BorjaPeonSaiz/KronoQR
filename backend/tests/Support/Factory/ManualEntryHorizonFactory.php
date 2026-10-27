<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\Policy\ManualEntryHorizon;
use DateTimeImmutable;
use DateTimeZone;

/**
 * El horizonte de RN-24 para las pruebas del agregado que no van a por el.
 *
 * `standard()` fija la hora del servidor en 2030 con los 5 minutos de serie:
 * ninguna marca de las pruebas (2026) queda por delante, asi que el horizonte
 * no interfiere con la regla que cada prueba ejercita. La prueba que va a por
 * RN-24 escribe su propio instante con {@see at()}.
 */
final class ManualEntryHorizonFactory
{
    /** Hora del servidor muy por delante de cualquier marca de las pruebas. */
    public static function standard(): ManualEntryHorizon
    {
        return self::at('2030-01-01 00:00:00');
    }

    /** La hora del servidor, en UTC, y el margen de `ATTENDANCE_FUTURE_TOLERANCE_MINUTES`. */
    public static function at(string $nowUtc, int $toleranceMinutes = 5): ManualEntryHorizon
    {
        return ManualEntryHorizon::at(new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')), $toleranceMinutes);
    }
}
