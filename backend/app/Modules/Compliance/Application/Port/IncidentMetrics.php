<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use DateTimeImmutable;

/**
 * Publica `incidents_open{type,severity}` (doc 02 §8.2).
 *
 * **Se recalcula entera, nunca se incrementa** (regla dura 7 aplicada a la
 * instrumentacion): es un gauge de «cuantas hay abiertas ahora», y la cifra tiene
 * que bajar cuando alguien resuelve una desde la bandeja. Por eso la escribe una
 * tarea programada que lee la tabla y no un efecto del detector.
 */
interface IncidentMetrics
{
    /**
     * @param  list<IncidentTally>  $tallies  Todas las combinaciones, incluidas las que estan a cero.
     */
    public function publish(array $tallies, DateTimeImmutable $at): void;
}
