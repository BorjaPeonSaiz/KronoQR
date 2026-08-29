<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishPresenceMetrics;
use Illuminate\Console\Command;

/**
 * `php artisan reporting:presence-metrics` — publica `open_shifts_current` y
 * `websocket_connections_active` (doc 02 §8.2, doc 01 §9.2, tarea 2.4).
 *
 * Lo ejecuta el planificador cada minuto (`routes/console.php`). A mano sirve
 * para comprobar que el fichero del colector *textfile* se escribe donde debe,
 * que es el fallo de instalacion mas frecuente de esta via.
 *
 * **No imprime ningun nombre de empleado** (regla dura 21): el resumen dice
 * cuantos turnos hay abiertos y en cuantos departamentos, y nada mas. Tampoco
 * deja asiento en `audit_log`: no hay divulgacion que registrar cuando lo unico
 * que sale es un recuento (mismo criterio que `credentials:status --quiet-table`).
 */
final class PresenceMetricsCommand extends Command
{
    protected $signature = 'reporting:presence-metrics';

    protected $description = 'Recalcula y publica las metricas de presencia en vivo (doc 02 §8.2).';

    public function handle(PublishPresenceMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn('Todavia no hay centro de trabajo: no hay nada que etiquetar (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado,
            // y un planificador que fallara cada minuto llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        $this->info('Metricas de presencia publicadas.');

        return self::SUCCESS;
    }
}
