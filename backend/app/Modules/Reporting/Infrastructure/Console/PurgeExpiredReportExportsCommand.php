<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
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
 * genero).
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
        ]);

        $this->info(
            'Informes en diferido purgados: '.$result->purged
            .'. Trabajos atascados liberados: '.$result->released
            .'. Ficheros huerfanos borrados: '.$result->orphans.'.'
        );

        return self::SUCCESS;
    }
}
