<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * Una fila de `daily_totals` **tal como esta escrita ahora mismo**, para poder
 * contrastarla con lo que dicen los eventos origen (RF-PR-02, ADR-007).
 *
 * No es un objeto del dominio y no debe llegar a serlo: es la fotografia de una
 * proyeccion que puede estar equivocada —ese es justo el supuesto de la
 * reconciliacion—, y darle metodos de negocio invitaria a razonar sobre ella
 * como si fuera un hecho. Los hechos son `shift_entries`; esto es una copia que
 * se comprueba.
 *
 * Los campos son los del doc 01 §5.5, uno a uno, porque la comparacion es
 * **campo a campo**: un total correcto con `has_open_shift` mal puesto deja el
 * panel de presencia mintiendo aunque las horas cuadren.
 */
final readonly class ProjectedDailyTotal
{
    public function __construct(
        public string $employeeUuid,
        /** Fecha civil en forma `YYYY-MM-DD`, tal como esta en la columna `DATE`. */
        public string $workDate,
        public int $totalMinutes,
        public int $shiftCount,
        public ?DateTimeImmutable $firstClockInAt,
        public ?DateTimeImmutable $lastClockOutAt,
        public bool $hasOpenShift,
        public bool $hasIncident,
        /** Cuando se escribio por ultima vez. La reconciliacion NO la compara. */
        public DateTimeImmutable $recalculatedAt,
    ) {}
}
