<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishAdoptionMetrics;
use Illuminate\Console\Command;

/**
 * `reporting:adoption-metrics` — recalcula `workdays_complete_ratio{site}`
 * (doc 02 §8.2, RF-IN-08, tarea 3.1).
 *
 * Misma mecanica que `reporting:presence-metrics` y por el mismo motivo: la
 * cifra se **recalcula entera** desde los datos y se publica por fichero para el
 * colector *textfile*. Lo unico que cambia es la cadencia —diaria, porque mide
 * una jornada ya cerrada— y que aqui no hay nada que mirar en vivo.
 */
final class AdoptionMetricsCommand extends Command
{
    protected $signature = 'reporting:adoption-metrics';

    protected $description = 'Recalcula y publica las metricas de adopcion de la jornada de ayer (doc 02 §8.2, RF-IN-08).';

    public function handle(PublishAdoptionMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn('Todavia no hay centro de trabajo: no hay jornada que medir (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo
            // esperado, y una tarea programada que fallara cada dia en una
            // instalacion nueva entrena a no mirar el planificador.
            return self::SUCCESS;
        }

        $this->info('Metricas de adopcion publicadas.');

        return self::SUCCESS;
    }
}
