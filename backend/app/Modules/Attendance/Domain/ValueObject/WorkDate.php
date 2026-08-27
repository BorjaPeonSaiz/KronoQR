<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\InvalidWorkDate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La jornada: una fecha civil **mas la zona en que es civil** (doc 01 §5.3).
 *
 * La zona forma parte del objeto porque sin ella RN-05 no es expresable: «la
 * fecha civil, en la zona del centro, del `clocked_in_at` del tramo que abre la
 * jornada». Un `2026-03-14` a secas no dice si las 23:30 UTC de ese dia son ese
 * dia o el siguiente, y esa ambiguedad es exactamente la que decide a que dia
 * se imputan ocho horas de un turno de noche.
 *
 * **El `WorkDate` es propiedad de la jornada, no del tramo** (ADR-006,
 * ADR-024). Lo resuelve `WorkDay` al abrirse y los tramos que la continuan
 * —la vuelta de una pausa a las 02:30— lo heredan. Si cada `ShiftEntry` lo
 * derivase de su propio `clocked_in_at`, fichar la pausa partiria la jornada
 * por la puerta de atras y dos personas del mismo turno acabarian con registros
 * diarios distintos segun si ficharon el descanso.
 *
 * No guarda ningun `DateTimeImmutable`: una fecha civil no es un instante, y
 * darle uno obligaria a elegir una hora que nadie ha decidido.
 */
final readonly class WorkDate
{
    private function __construct(
        /** Fecha civil en forma `YYYY-MM-DD`, tal como se almacena en `shift_entries.work_date`. */
        public string $isoDate,
        /** Zona del centro (`sites.timezone`), servida por el puerto `SiteCalendar`. */
        public DateTimeZone $timezone,
    ) {}

    /**
     * RN-05: la fecha civil, en la zona del centro, del instante que abre la
     * jornada.
     *
     * `setTimezone` no mueve el instante, solo cambia el cristal por el que se
     * mira: por eso esto es correcto tambien el dia del cambio de hora (RN-09).
     */
    public static function fromInstant(DateTimeImmutable $instant, DateTimeZone $timezone): self
    {
        TimeRange::assertUtc('instant', $instant);

        return new self($instant->setTimezone($timezone)->format('Y-m-d'), $timezone);
    }

    /**
     * Reconstruccion desde la base de datos, donde `work_date` es un `DATE` y la
     * zona vive en `sites.timezone`.
     */
    public static function fromIsoDate(string $isoDate, DateTimeZone $timezone): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $parts) !== 1) {
            throw InvalidWorkDate::notACivilDate($isoDate);
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw InvalidWorkDate::notACivilDate($isoDate);
        }

        return new self($isoDate, $timezone);
    }

    /**
     * Dos jornadas son la misma si coinciden fecha y zona. La zona cuenta: el
     * mismo `2026-03-14` de dos centros en husos distintos son dos jornadas
     * diferentes, y `daily_totals` las indexa por empleado, que pertenece a uno.
     */
    public function equals(self $other): bool
    {
        return $this->isoDate === $other->isoDate
            && $this->timezone->getName() === $other->timezone->getName();
    }
}
