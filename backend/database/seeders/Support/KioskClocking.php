<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Un tramo sembrado como si lo hubiera fichado el quiosco: lo que hace falta
 * para escribir sus asientos de `audit_log` ({@see KioskClockingAudit}).
 *
 * Los dos instantes se normalizan a UTC al construirlo: el asiento los escribe
 * con una `Z` literal, y un instante en otra zona daria una marca falsa que la
 * conciliacion denunciaria como `entry_differs_from_audit` (regla dura 3).
 */
final readonly class KioskClocking
{
    public DateTimeImmutable $clockedInAt;

    public ?DateTimeImmutable $clockedOutAt;

    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public string $shiftEntryUuid,
        /** `shift_entries.work_date`, en forma `YYYY-MM-DD`. */
        public string $workDate,
        public DateTimeZone $timezone,
        DateTimeImmutable $clockedInAt,
        ?DateTimeImmutable $clockedOutAt,
        /** Total de la jornada al cerrar este tramo, sumando los vigentes ya cerrados de ese dia (RN-06). */
        public int $dailyTotalMinutes = 0,
    ) {
        $utc = new DateTimeZone('UTC');

        $this->clockedInAt = $clockedInAt->setTimezone($utc);
        $this->clockedOutAt = $clockedOutAt?->setTimezone($utc);

        if ($this->clockedOutAt !== null && $this->clockedOutAt <= $this->clockedInAt) {
            throw new InvalidArgumentException('A seeded shift entry must end after it starts (RN-03).');
        }
    }

    /**
     * Desde las columnas de una fila de `shift_entries`, tal y como las devuelve
     * PostgreSQL (`2026-07-16 05:00:00+00`).
     */
    public static function fromColumns(
        string $employeeUuid,
        int $siteId,
        string $shiftEntryUuid,
        string $workDate,
        DateTimeZone $timezone,
        string $clockedInAt,
        ?string $clockedOutAt,
        int $dailyTotalMinutes = 0,
    ): self {
        return new self(
            $employeeUuid,
            $siteId,
            $shiftEntryUuid,
            $workDate,
            $timezone,
            new DateTimeImmutable($clockedInAt),
            $clockedOutAt === null ? null : new DateTimeImmutable($clockedOutAt),
            $dailyTotalMinutes,
        );
    }
}
