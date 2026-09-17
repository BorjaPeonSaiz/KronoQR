<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use DateTimeImmutable;

/**
 * Los **seis** campos de una jornada en `daily_totals`, para poder contar en un
 * asiento que decia la fila antes y que dice despues (RF-PR-02, RL-04).
 *
 * **Existe porque el antes y el despues tienen que ser completos.** El asiento de
 * la reconciliacion llevaba solo el total y el numero de tramos, y desde que la
 * correccion no escribe nada cuando la sospecha se deshace sola (tarea 3.6) ese
 * asiento es la **unica** copia de lo que la proyeccion afirmaba. Con dos de los
 * seis campos no se puede reconstruir una fila que decia `has_open_shift = true`
 * sobre un turno cerrado, que es justamente la clase de divergencia que se vio en
 * la prueba de carga.
 *
 * Son los seis que compara la reconciliacion, ni uno mas: `recalculated_at` se
 * queda fuera por lo mismo que no entra en la comparacion —es cuando se escribio
 * la fila, no lo que la fila afirma—.
 *
 * **Escalares y accesores ISO** porque quien lee esto es un listener de
 * `Compliance`, que solo puede alcanzar `Attendance\Domain\Event` (doc 02 §1.6,
 * verificado por Deptrac) y no puede nombrar ningun objeto de valor de este
 * modulo. Mismo criterio que `DailyTotalsReconciled::workDateIso()`.
 *
 * **Nunca lleva nombres** (regla dura 21): describe una fila, no a una persona.
 */
final readonly class DailyTotalsSnapshot
{
    public function __construct(
        public int $totalMinutes,
        public int $shiftCount,
        public ?DateTimeImmutable $firstClockInAt,
        public ?DateTimeImmutable $lastClockOutAt,
        public bool $hasOpenShift,
        public bool $hasIncident,
    ) {}

    /**
     * La primera entrada del dia en ISO 8601 con microsegundos, o `null`.
     *
     * Con microsegundos y con desplazamiento explicito: es el mismo formato con
     * el que se guardan los instantes del producto, y un asiento que redondeara
     * al segundo no permitiria comparar la fila mala con el tramo que la origino.
     */
    public function firstClockInAtIso(): ?string
    {
        return $this->firstClockInAt?->format('Y-m-d\TH:i:s.uP');
    }

    public function lastClockOutAtIso(): ?string
    {
        return $this->lastClockOutAt?->format('Y-m-d\TH:i:s.uP');
    }
}
