<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishAbsenceMetrics;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan reporting:absence-metrics` — publica `absences_current{type}`
 * (doc 02 §8.2, RF-GP-04).
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
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las metricas de `reporting:*`. */
    private const string TEXTS = 'console.reporting_metrics';

    protected $signature = 'reporting:absence-metrics';

    protected $description = 'Recalcula y publica las personas ausentes hoy, por tipo (doc 02 §8.2).';

    public function handle(PublishAbsenceMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn($this->text('absences.no_site'));

            // Cero y no error: antes de la puesta en marcha esto es lo esperado, y
            // un planificador que fallara cada noche llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        $this->info($this->text('absences.published'));

        return self::SUCCESS;
    }
}
