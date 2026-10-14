<?php

declare(strict_types=1);

namespace Tests\Support\Time;

/**
 * Estadistica de muestras de tiempo para las pruebas de tiempo constante
 * (RS-03, regla dura 17).
 *
 * Una sola implementacion para todas: hasta la 2.2.1 cada fichero llevaba la
 * suya, y una de ellas tomaba el elemento central superior con un numero par
 * de muestras en vez de promediar los dos centrales.
 */
final class Samples
{
    /**
     * Mediana y no media: una sola muestra lenta —el planificador del
     * contenedor, un `checkpoint` de PostgreSQL— desplazaria la media y haria
     * fallar la prueba sin que hubiera ningun canal de tiempo. Con un numero
     * par de muestras, la media de las dos centrales.
     *
     * @param  list<float|int>  $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Sin muestras no hay mediana.');
        }

        sort($values);

        $middle = \count($values) >> 1;

        return \count($values) % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
