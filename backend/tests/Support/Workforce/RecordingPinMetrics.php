<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Workforce\Application\Port\PinMetrics;

/**
 * Doble de `pin_resets_total{site}` que recuerda lo medido.
 *
 * Mismo papel que `RecordingScanMetrics`: lo que hay que comprobar es que el
 * caso de uso llama al puerto y con que etiqueta, y eso se verifica con un
 * doble, no levantando Redis ni Prometheus.
 */
final class RecordingPinMetrics implements PinMetrics
{
    /**
     * Restablecimientos contados por centro.
     *
     * @var array<int, int>
     */
    public array $resets = [];

    public function pinReset(int $siteId): void
    {
        $this->resets[$siteId] = ($this->resets[$siteId] ?? 0) + 1;
    }
}
