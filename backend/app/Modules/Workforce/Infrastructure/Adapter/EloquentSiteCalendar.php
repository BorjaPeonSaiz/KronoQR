<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Workforce\Infrastructure\Persistence\Site;
use DateTimeZone;

/**
 * La zona horaria del centro, sin la cual RN-05 no es expresable.
 *
 * Segunda arista de ADR-025: el puerto es de `Attendance`, la tabla `sites` es
 * de `Workforce`.
 *
 * **Se lee de la fila y no de `APP_TIMEZONE`.** El proceso corre siempre en UTC
 * (regla dura 3) y una instalacion puede tener hoteles en husos distintos: un
 * centro en Canarias y otro en la peninsula no comparten fecha civil a las
 * 00:30, y la jornada de un turno de noche depende de esa diferencia.
 */
final readonly class EloquentSiteCalendar implements SiteCalendar
{
    public function timezoneOf(int $siteId): ?DateTimeZone
    {
        $row = Site::query()->select(['timezone'])->find($siteId);

        if (! $row instanceof Site) {
            // Un empleado adscrito a un centro que no esta es una inconsistencia
            // de datos: quien decide que hacer es el caso de uso, que registrara
            // el escaneo y abrira incidencia antes que dejar a alguien sin
            // jornada (regla dura 19).
            return null;
        }

        return new DateTimeZone($row->timezone);
    }
}
