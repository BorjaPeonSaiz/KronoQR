<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishAbsenceMetrics;
use Illuminate\Console\Command;

/**
 * `php artisan reporting:absence-metrics` — publica `absences_current{type}`
 * (doc 02 §8.2, decision 8 de la ficha 3.10, RF-GP-04).
 *
 * Lo ejecuta el planificador cada noche, con la misma cadencia que
 * `reporting:compliance-metrics`. A mano sirve para comprobar que el fichero del
 * colector *textfile* se escribe donde debe, que es el fallo de instalacion mas
 * frecuente de esta via.
 *
 * **No imprime ni un nombre ni un tipo de ausencia** (regla dura 21): una baja
 * medica es dato de salud, y la salida de un comando acaba en el log del
 * planificador. El resumen dice que se publico y nada mas.
 */
final class AbsenceMetricsCommand extends Command
{
    protected $signature = 'reporting:absence-metrics';

    protected $description = 'Recalcula y publica las personas ausentes hoy, por tipo (doc 02 §8.2).';

    public function handle(PublishAbsenceMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn('Todavia no hay centro de trabajo: no hay zona con la que resolver el dia de hoy (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado, y
            // un planificador que fallara cada noche llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        $this->info('Metricas de ausencias publicadas.');

        return self::SUCCESS;
    }
}
