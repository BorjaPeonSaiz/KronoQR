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
 *
 * Por lo mismo se publica `$failures`, que alimenta
 * `projection_reconciliation_last_failures` (doc 02 §8.2, tarea 3.2): la pasada
 * puede encontrar una divergencia y **no conseguir corregirla**, y hasta ahora
 * eso solo constaba en el codigo de salida del comando, que no llegaba a
 * ninguna alerta. Es un gauge de la ultima pasada —«¿la de anoche dejo algo sin
 * hacer?»— y no un contador, y lo vigila `ReconciliacionConFallos`
 * (`infra/observability/prometheus/rules/projection.yml`).
 */
interface ProjectionMetrics
{
    /**
     * @param  int  $workDaysInspected  jornadas contrastadas en la pasada
     * @param  int  $divergences  filas que no coincidian con sus eventos origen
     * @param  int  $corrected  de las anteriores, cuantas se reescribieron
     * @param  int  $failures  jornadas que la pasada NO pudo dejar resueltas
     */
    public function reconciliationCompleted(
        int $workDaysInspected,
        int $divergences,
        int $corrected,
        int $failures,
        DateTimeImmutable $at,
    ): void;
}
