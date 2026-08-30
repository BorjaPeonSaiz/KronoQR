<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * `projection_divergence_total` y el rastro de que la reconciliacion se ejecuta
 * (doc 02 §8.2, RF-PR-02).
 *
 * **La metrica debe permanecer siempre en cero.** El §8.2 la nombra junto a
 * `audit_chain_verification_failures_total` y dice de las dos lo mismo:
 * cualquier incremento es un **incidente de integridad**, no una tendencia que
 * se vigile por percentiles. Una divergencia significa que algo escribio
 * `daily_totals` por un camino que no es el recalculo (ADR-007).
 *
 * Por eso el puerto no tiene un `incrementar()` suelto: se publica **el
 * resultado de una pasada completa**, con su momento y sus jornadas revisadas.
 * Sin esa segunda mitad, apagar la tarea programada seria la forma mas comoda de
 * que la metrica no volviera a subir nunca — y el silencio se leeria igual que
 * la integridad.
 */
interface ProjectionMetrics
{
    /**
     * @param  int  $workDaysInspected  jornadas contrastadas en la pasada
     * @param  int  $divergences  filas que no coincidian con sus eventos origen
     * @param  int  $corrected  de las anteriores, cuantas se reescribieron
     */
    public function reconciliationCompleted(
        int $workDaysInspected,
        int $divergences,
        int $corrected,
        DateTimeImmutable $at,
    ): void;
}
