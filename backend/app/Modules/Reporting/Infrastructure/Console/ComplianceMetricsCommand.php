<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishComplianceMetrics;
use Illuminate\Console\Command;

/**
 * `php artisan reporting:compliance-metrics` — publica
 * `compliance_findings_last_week` y `compliance_employees_affected_last_week`
 * (doc 02 §8.2, decision 10 de la ficha 3.4).
 *
 * Lo ejecuta el planificador cada noche **despues** de
 * `attendance:detect-incidents`: la vista enlaza cada hallazgo con la incidencia
 * de la bandeja, y publicando antes de la deteccion el enlace faltaria justo en
 * las jornadas de la vispera. A mano sirve para comprobar que el fichero del
 * colector *textfile* se escribe donde debe, que es el fallo de instalacion mas
 * frecuente de esta via.
 *
 * **No imprime ningun nombre de empleado** (regla dura 21): el resumen dice cuantos
 * hallazgos y cuantas personas, y nada mas.
 */
final class ComplianceMetricsCommand extends Command
{
    protected $signature = 'reporting:compliance-metrics';

    protected $description = 'Recalcula y publica las metricas de cumplimiento de la ultima semana completa (doc 02 §8.2).';

    public function handle(PublishComplianceMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn('Todavia no hay centro de trabajo: no hay perfil con el que evaluar (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado, y
            // un planificador que fallara cada noche llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        $this->info('Metricas de cumplimiento publicadas.');

        return self::SUCCESS;
    }
}
