<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\UseCase\SweepLegalExportFiles;
use App\Modules\Shared\Infrastructure\Console\InstallationText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:purge-legal-export-temp` — borra los temporales
 * huerfanos de la descarga HTTP de la exportacion legal y cuenta las copias de
 * consola que llevan demasiado tiempo en el servidor (RF-IN-05, hallazgo
 * MEDIO-3 del cierre de la Fase 1; ADR-045).
 *
 * ## Que borra y que no
 *
 * SOLO `storage/app/tmp/legal-exports/`: el temporal que `LegalExportController`
 * crea para servir `GET /api/v1/reports/legal-export` y borra con
 * `deleteFileAfterSend()` al terminar. Si quien descarga aborta la conexion a
 * medias, ese borrado nunca corre y el fichero -con datos personales de la
 * plantilla- se queda en disco. Vivia en `storage/framework`, en la capa de
 * `app`, y este comando corre en `scheduler`: no lo veia (ADR-045). Solo
 * ficheros `registro-horario-*.csv` de un nivel, sin seguir enlaces.
 *
 * NUNCA toca `storage/app/legal-exports/`: es la copia deliberada que
 * `compliance:legal-export` escribe para entregar a la Inspeccion. Esa la
 * custodia y la borra quien la genero, no un cron automatico (regla dura 16 y
 * docs/runbooks/requerimiento-inspeccion.md §6). Lo que si hace es **contar**
 * las que llevan mas de 30 dias y publicarlo en una metrica con alerta.
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

    public function handle(SweepLegalExportFiles $sweep): int
    {
        $result = $sweep->handle();
        $retentionHours = config()->integer('compliance.legal_export_temp_retention_hours');

        // Sin rutas ni nombres de fichero en el log (regla dura 21): el
        // nombre del temporal lleva el periodo, y el log tecnico viaja en el
        // paquete de diagnostico.
        Log::info('compliance.legal_export_temp_purged', [
            'deleted' => $result['removed'],
            'retention_hours' => $retentionHours,
            'console_overdue' => $result['overdue'],
        ]);

        $this->info(self::text('generated-files.legal_exports.temporaries', [
            'count' => $result['removed'],
            'hours' => $retentionHours,
        ]));

        if ($result['overdue'] > 0) {
            $this->warn(self::text('generated-files.legal_exports.console_overdue', [
                'count' => $result['overdue'],
                'days' => config()->integer('compliance.legal_export_console_warning_days'),
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * Un texto de `lang/*\/generated-files.php` en el idioma de la INSTALACION,
     * no en `APP_LOCALE` (ver `InstallationText`).
     *
     * @param  array<string, int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return app(InstallationText::class)->line($key, $replace);
    }
}
