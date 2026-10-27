<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
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
     * Intentos con claim **de esa persona** cuyo `occurred_at` ∈ [from, to),
     * con el mismo orden.
     *
     * Es la jornada entera, llegara cuando llegara: antes de dar una jornada por
     * subsanada y cerrar su incidencia, la revision la mira completa y no solo lo
     * que cayo en su ventana de `recorded_at`. Sin esto, un intento sin subsanar
     * recibido justo antes del borde de la ventana desapareceria de la cuenta y
     * la incidencia se cerraria con el todavia pendiente.
     *
     * @return list<RejectedPinAttempt>
     */
    public function rejectedOf(string $claimantUuid, DateTimeImmutable $fromOccurredAt, DateTimeImmutable $toOccurredAt): array;

    /**
     * `occurred_at` de los fichajes de esa persona que subsanan
     * ({@see PinAttemptRecoveryPolicy::recovers()}: la lista la decide el
     * dominio) con `occurred_at` ∈ [from, to], ambos incluidos.
     *
     * @return list<DateTimeImmutable>
     */
    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
