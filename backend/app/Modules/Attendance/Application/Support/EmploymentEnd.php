<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * **Donde acaba la relacion laboral para RN-20**: el primer instante del dia
 * civil siguiente al cese, en la zona del centro, o `null` sin cese o sin zona
 * (RN-14, ADR-062 §8).
 *
 * Despues de ese instante una tarjeta retirada no pide revision, aunque sea
 * anterior a su retirada: es lo que pasa entre el dia del cese y el momento en
 * que la tarea aplica una baja programada. Un solo sitio para los tres caminos
 * que lo preguntan —el fichaje y los dos avisos de fichaje descartado (RN-22)—,
 * para que la cota no difiera entre ellos.
 *
 * **La zona solo se consulta con fecha de cese**, que es el camino de una
 * tarjeta autentica de alguien con baja: los demas no pagan la consulta.
 */
final class EmploymentEnd
{
    public static function of(SiteCalendar $calendar, ?string $terminatedOn, ?int $siteId): ?DateTimeImmutable
    {
        if ($terminatedOn === null || $siteId === null) {
            return null;
        }

        $timezone = $calendar->timezoneOf($siteId);

        return $timezone instanceof DateTimeZone ? WorkDate::fromIsoDate($terminatedOn, $timezone)->endsAt() : null;
    }
}
