<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use DateTimeImmutable;

/**
 * Los fichajes por PIN rechazados **con dueño** y los fichajes que los subsanan
 * (RN-19, ADR-043).
 *
 * Lee hacia atras lo que el fichaje ya escribio —`scan_events.
 * claimed_employee_id`—, igual que {@see OutOfOrderScans} con RN-18: sin evento,
 * listener ni proceso nuevos dentro de la transaccion del fichaje.
 *
 * **La ventana es de `recorded_at`** por el mismo motivo que en RN-18 (doc 01
 * §4): la cola offline drena con dias de retraso, y el intento que mas necesita
 * revision es justo el que mas tardo en llegar. La jornada del hallazgo sigue
 * saliendo del `occurred_at` en la zona del centro (RN-05).
 */
interface RejectedPinScans
{
    /**
     * Intentos con claim cuyo `recorded_at` ∈ [from, to], ordenados por
     * `occurred_at` ASC y `scan_id` ASC.
     *
     * @return list<RejectedPinAttempt>
     */
    public function rejectedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array;

    /**
     * `occurred_at` de los fichajes que subsanan (`clock_in`, `clock_out`,
     * `break_start`, `break_end`, `rejected_debounce`, `rejected_out_of_order`)
     * de esa persona con `occurred_at` ∈ [from, to], ambos incluidos.
     *
     * @return list<DateTimeImmutable>
     */
    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
