<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\TelemetryCounters;

/**
 * Contadores de mentira que **cuentan cuantas veces se les pregunta**
 * (RF-PD-12).
 *
 * Es la mitad de la prueba de que con la telemetria apagada no se construye
 * nada: si alguien invirtiera el orden y montara el informe antes de mirar las
 * tres condiciones, `$calls` seria `1` y la prueba lo diria.
 */
final class SpyTelemetryCounters implements TelemetryCounters
{
    public int $calls = 0;

    /**
     * @param  array<string, int>  $counters
     */
    public function __construct(private readonly array $counters = []) {}

    public function snapshot(): array
    {
        $this->calls++;

        return $this->counters;
    }
}
