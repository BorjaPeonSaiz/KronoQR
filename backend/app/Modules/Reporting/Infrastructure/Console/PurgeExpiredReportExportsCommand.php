<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Shared\Infrastructure\Console\InstallationText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan reporting:purge-expired-exports` — borra los ficheros de informe
 * caducados y libera los trabajos atascados (**RF-IN-06**, regla dura 5).
 *
 * ## Que borra
 *
 * SOLO el **fichero** de las exportaciones cuyo `expires_at` ya paso, dentro de
 * `REPORTING_EXPORT_PATH`. La fila se queda para siempre marcada como `purged`
 * con sus fechas, su huella y su recuento: «¿salio de aqui un informe con las
 * horas de mi plantilla, cuando y a peticion de quien?» hay que poder
 * contestarlo despues, y una fila borrada no contesta nada.
 *
 * **Nunca toca `storage/app/legal-exports/`** ni ningun otro directorio: la
 * exportacion para la Inspeccion tiene su propia limpieza y su propio criterio
 * (aquella es la copia que se entrega a un tercero y la custodia quien la
 * genero). Solo actua sobre directorios `<uuid>/` de un nivel bajo
 * `REPORTING_EXPORT_PATH`, sin seguir enlaces (ADR-045, C3).
 *
 * ## Y concilia fila y fichero (ADR-045)
 *
 * La fila `completed` cuyo fichero ya no esta pasa a `purged`, y si aun no habia
 * caducado deja `report_export.file_missing`. El directorio que ninguna fila
 * viva nombra se borra al superar su edad minima.
 *
 * ## Y desatasca, que es la otra mitad
 *
 * Una fila `pending` o `running` mas vieja que `REPORTING_EXPORT_STALE_AFTER`
 * pasa a `failed` con motivo `stale`. Sin eso, un servidor parado a mitad
 * —el paso 1 de cualquier actualizacion— dejaria a esa persona con `409` al
 * pedir el siguiente informe hasta que alguien entrara por `psql`.
 *
 * ## El codigo de salida es siempre `0`
 *
 * No hay nada que fallar: si no hay nada que purgar, no hay nada que purgar. Un
 * codigo distinto de cero dispararia `LogScheduledCommandFailure` y una alerta
 * por una tarea que hizo exactamente lo que tenia que hacer.
 *
 * El log no lleva ni rutas ni nombres (regla dura 21): dos recuentos.
 */
final class PurgeExpiredReportExportsCommand extends Command
{
    protected $signature = 'reporting:purge-expired-exports';

    protected $description = 'Borra los ficheros de informe en diferido caducados y libera los trabajos atascados (RF-IN-06)';

    public function handle(PurgeExpiredReportExports $maintenance): int
    {
        $result = $maintenance->handle();

        Log::info('reporting.report_exports_purged', [
            'purged' => $result->purged,
            'released' => $result->released,
            'orphans' => $result->orphans,
            'missing' => $result->missing,
        ]);

        $this->info(self::text('generated-files.report_exports.summary', [
            'purged' => $result->purged,
            'released' => $result->released,
            'orphans' => $result->orphans,
        ]));

        if ($result->missing > 0) {
            // En voz alta: un informe con horas nominales ha desaparecido antes de
            // caducar. Tras una restauracion es lo esperado; fuera de eso, alguien
            // lo borro o se lo llevo, y el asiento permite acotar cuando.
            $this->warn(self::text('generated-files.report_exports.missing', ['count' => $result->missing]));
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
