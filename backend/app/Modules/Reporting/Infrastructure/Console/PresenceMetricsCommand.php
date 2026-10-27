<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PublishPresenceMetrics;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan reporting:presence-metrics` — publica `open_shifts_current` y
 * `websocket_connections_active` (doc 02 §8.2, doc 01 §9.2).
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
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las metricas de `reporting:*`. */
    private const string TEXTS = 'console.reporting_metrics';

    protected $signature = 'reporting:presence-metrics';

    protected $description = 'Recalcula y publica las metricas de presencia en vivo (doc 02 §8.2).';

    public function handle(PublishPresenceMetrics $metrics): int
    {
        if (! $metrics->handle()) {
            $this->warn($this->text('presence.no_site'), 'v');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado,
            // y un planificador que fallara cada minuto llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        // Las dos lineas, solo con `-v`: corre cada minuto y su salida va al log
        // del planificador (`ScheduledTaskOutput`). Una linea por minuto no dice
        // nada que no digan ya las metricas; un fallo si llega, porque sale por
        // la salida de error del comando.
        $this->info($this->text('presence.published'), 'v');

        return self::SUCCESS;
    }
}
