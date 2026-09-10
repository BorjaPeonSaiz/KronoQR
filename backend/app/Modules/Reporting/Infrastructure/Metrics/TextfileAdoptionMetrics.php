<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\AdoptionMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Publica `workdays_complete_ratio{site}` para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2, RF-IN-08).
 *
 * ```
 * workdays_complete_ratio{site="1"}
 * workdays_complete_ratio_work_days{site="1"}
 * adoption_metrics_work_date_seconds
 * adoption_metrics_timestamp_seconds
 * ```
 *
 * ## Por fichero y no por Redis, al reves que `scans_by_origin_total`
 *
 * Las dos alimentan el mismo cuadro de mando, y la diferencia es que tipo de
 * numero es cada una. `scans_by_origin_total` es un contador puro que solo
 * suma: `HINCRBY` y listo. Esto es un **ratio que se recalcula entero desde los
 * datos** cada vez (regla dura 7 aplicada a la instrumentacion), y lo produce un
 * comando programado que corre de madrugada y termina. Un valor recalculado que
 * viviera en una cache que un despliegue puede vaciar dejaria de existir hasta
 * la noche siguiente; en un fichero sigue ahi.
 *
 * ## Un centro sin jornadas NO publica serie
 *
 * Ni cero ni `NaN`. Cero se lee como «ese dia nadie cerro su jornada», que es
 * una alarma, y lo que ocurre de verdad es que el centro estaba cerrado. La
 * ausencia es la unica forma honesta de decir «no hay nada que medir» —el mismo
 * criterio que `websocket_connections_active` (ADR-011)—.
 *
 * ## Las dos series auxiliares
 *
 * `workdays_complete_ratio_work_days` da el **denominador**: un ratio de 0,5
 * sobre dos jornadas y uno sobre doscientas no significan lo mismo, y sin el
 * tamaño de la muestra el panel invita a conclusiones que no se sostienen.
 * `adoption_metrics_work_date_seconds` dice **que dia** se midio: sin ella, una
 * tarea programada que lleve una semana caida se lee exactamente igual que una
 * instalacion en la que nada ha cambiado.
 *
 * ## La etiqueta es el identificador del centro, no su nombre
 *
 * Al reves que `open_shifts_current`, que si lleva `site_name` porque la lee una
 * persona a las seis de la mañana durante un cambio de turno. Esta la lee un
 * cuadro de direccion junto a `pin_fallback_scans_total{site}` y
 * `worked_minutes_total{site,…}`, que ya van por identificador: mezclarlos
 * obligaria a cruzar dos claves distintas para el mismo centro.
 */
final readonly class TextfileAdoptionMetrics implements AdoptionMetrics
{
    private const string FILE = 'kronoqr_adoption.prom';

    public function publish(array $bySite, string $workDate, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP workdays_complete_ratio Proporcion de jornadas de la fecha medida que quedaron con todos sus tramos cerrados (RF-IN-08). Su ausencia significa que ese centro no tuvo ninguna jornada, que no es lo mismo que cero.',
            '# TYPE workdays_complete_ratio gauge',
        ];

        ksort($bySite, SORT_NUMERIC);

        $ratios = [];
        $totals = [];

        foreach ($bySite as $siteId => $counts) {
            if ($counts['total'] < 1) {
                continue;
            }

            $ratios[] = 'workdays_complete_ratio{site="'.$siteId.'"} '
                // Seis decimales: un hotel de trescientas personas necesita
                // distinguir una jornada suelta sin cerrar, y `1/300` en dos
                // decimales se redondea a `1.00`.
                .number_format($counts['complete'] / $counts['total'], 6, '.', '');

            $totals[] = 'workdays_complete_ratio_work_days{site="'.$siteId.'"} '.$counts['total'];
        }

        $lines = [...$lines, ...$ratios];

        if ($totals !== []) {
            $lines[] = '# HELP workdays_complete_ratio_work_days Jornadas con algun tramo en la fecha medida: el denominador del ratio. Un ratio sin su muestra no se puede interpretar.';
            $lines[] = '# TYPE workdays_complete_ratio_work_days gauge';
            $lines = [...$lines, ...$totals];
        }

        $lines[] = '# HELP adoption_metrics_work_date_seconds Fecha de la jornada medida, a medianoche UTC. Delata una tarea programada que dejo de ejecutarse: sin ella, una semana caida se lee igual que una semana sin cambios.';
        $lines[] = '# TYPE adoption_metrics_work_date_seconds gauge';
        $lines[] = 'adoption_metrics_work_date_seconds '.$this->midnightOf($workDate);

        $lines[] = '# HELP adoption_metrics_timestamp_seconds Momento del ultimo recalculo.';
        $lines[] = '# TYPE adoption_metrics_timestamp_seconds gauge';
        $lines[] = 'adoption_metrics_timestamp_seconds '.$at->getTimestamp();

        TextfileExposition::write(self::FILE, $lines);
    }

    /**
     * La fecha civil como instante, para que sea un numero y no una etiqueta.
     *
     * **Como etiqueta seria una serie nueva cada dia**, que es la forma clasica
     * de hacer estallar la cardinalidad de un TSDB. Como valor, es una cifra que
     * sube 86.400 al dia y cuya falta de movimiento es justo la alerta que
     * interesa.
     */
    private function midnightOf(string $workDate): int
    {
        $midnight = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $workDate,
            new DateTimeZone('UTC'),
        );

        return $midnight === false ? 0 : $midnight->getTimestamp();
    }
}
