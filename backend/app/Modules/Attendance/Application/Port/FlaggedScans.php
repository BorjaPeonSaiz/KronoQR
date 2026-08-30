<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * Los escaneos marcados para revision dentro de una ventana (RN-15, RF-AT-10).
 *
 * **Lee hacia atras la columna que el fichaje ya escribe.** `ReviewPolicy` marca
 * `scan_events.flagged_for_review` en el momento del escaneo; sin este puerto,
 * esa marca se guardaba y no la miraba nadie —una marca retrodatada entraba en el
 * registro legal indistinguible de un fichaje normal—. La deteccion la convierte
 * en incidencia `clock_skew` sin volver a decidir nada que ya se decidio.
 *
 * La sirve el indice parcial `scan_events_flagged_for_review_index`, que solo
 * contiene las filas marcadas.
 */
interface FlaggedScans
{
    /**
     * Escaneos marcados cuyo `occurred_at` cae en la ventana, del mas reciente
     * al mas antiguo.
     *
     * Se filtra por `occurred_at` —el momento real— y no por `recorded_at`,
     * porque la ventana de retroactividad habla de jornadas, y la jornada de un
     * fichaje offline es la de cuando ocurrio, no la de cuando llego (regla dura
     * 9).
     *
     * @return list<FlaggedScan>
     */
    public function flaggedBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
