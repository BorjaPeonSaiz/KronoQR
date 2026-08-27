<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:purge-legal-export-temp` — borra los temporales
 * huerfanos de la descarga HTTP de la exportacion legal (RF-IN-05, hallazgo
 * MEDIO-3 del cierre de la Fase 1).
 *
 * ## Que borra y que no
 *
 * SOLO `storage/framework/legal-exports/`: el temporal que
 * `LegalExportController` crea para servir `GET /api/v1/reports/legal-export`
 * y borra con `deleteFileAfterSend()` al terminar. Si quien descarga aborta la
 * conexion a medias, ese borrado nunca corre y el fichero -con datos
 * personales de la plantilla- se queda en disco.
 *
 * NUNCA toca `storage/app/legal-exports/`: es la copia deliberada que
 * `compliance:legal-export` escribe para entregar a la Inspeccion. Esa la
 * custodia y la borra quien la genero, no un cron automatico (regla dura 16 y
 * docs/runbooks/requerimiento-inspeccion.md §6): un borrado programado sobre
 * la unica copia que se le entrega a un tercero convertiria una limpieza en
 * una perdida de prueba.
 *
 * ## La ventana
 *
 * Un fichero mas joven que `compliance.legal_export_temp_retention_hours`
 * puede ser una descarga legitima en curso -streaming sobre una red lenta, un
 * periodo largo-, asi que no se toca. Uno mas viejo que la ventana ya no es
 * una descarga en curso: es un huerfano.
 */
final class PurgeOrphanedLegalExportTempFilesCommand extends Command
{
    protected $signature = 'compliance:purge-legal-export-temp';

    protected $description = 'Borra los temporales huerfanos de la descarga HTTP de la exportacion legal; nunca la copia de consola (RF-IN-05)';

    public function handle(): int
    {
        $directory = storage_path('framework/legal-exports');

        if (! is_dir($directory)) {
            $this->info('No hay directorio de temporales que limpiar: '.$directory);

            return self::SUCCESS;
        }

        $retentionHours = config()->integer('compliance.legal_export_temp_retention_hours');
        $cutoff = time() - ($retentionHours * 3600);
        $deleted = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.csv') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $modifiedAt = filemtime($file);

            if ($modifiedAt !== false && $modifiedAt < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        // Sin rutas ni nombres de fichero en el log (regla dura 21): el
        // nombre del temporal no lleva PII, pero el habito de no volcar
        // detalle que pueda acabar en el paquete de diagnostico se mantiene
        // igual para todo lo que toca este directorio.
        Log::info('compliance.legal_export_temp_purged', [
            'deleted' => $deleted,
            'retention_hours' => $retentionHours,
        ]);

        $this->info('Temporales huerfanos borrados: '.$deleted.' (ventana: '.$retentionHours.' h).');

        return self::SUCCESS;
    }
}
