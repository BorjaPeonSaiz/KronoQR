<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\UseCase\PublishIncidentMetrics;
use Illuminate\Console\Command;

/**
 * `php artisan compliance:incident-metrics` — publica `incidents_open{type,severity}`
 * (doc 02 §8.2, doc 01 §9.2, tarea 2.6).
 *
 * Lo ejecuta el planificador (`routes/console.php`). A mano sirve para comprobar
 * que el fichero del colector *textfile* se escribe donde debe, que es el fallo
 * de instalacion mas frecuente de esta via.
 *
 * **Separado de la deteccion a proposito.** Si la metrica se publicara al final
 * de `attendance:detect-incidents`, solo cambiaria cuando se detecta algo: una
 * incidencia resuelta desde la bandeja a las once de la manana no bajaria la
 * cifra hasta la madrugada siguiente, y la alerta de turnos abiertos seguiria
 * sonando sobre algo ya resuelto.
 *
 * **No imprime ningun nombre** (regla dura 21) ni deja asiento en `audit_log`: lo
 * unico que sale es un recuento, que no es una divulgacion (ADR-037, condicion 3).
 */
final class IncidentMetricsCommand extends Command
{
    protected $signature = 'compliance:incident-metrics';

    protected $description = 'Recalcula y publica el gauge de incidencias abiertas (doc 02 §8.2).';

    public function handle(PublishIncidentMetrics $metrics): int
    {
        $open = $metrics->handle();

        $this->info('Metrica publicada: '.$open.' incidencia(s) abiertas.');

        return self::SUCCESS;
    }
}
