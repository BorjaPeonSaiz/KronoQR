<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Observa `incident_resolution_seconds{type}` (doc 02 §8.2).
 *
 * ## Por que no comparte puerto con `IncidentMetrics`
 *
 * Aquel publica un **gauge** que se recalcula entero desde la tabla en una tarea
 * programada: «cuantas hay abiertas ahora». Esto es un **histograma** que se
 * observa una vez, en el momento en que una persona cierra una incidencia, y no
 * se puede reconstruir leyendo nada —la distribucion de lo que tardo cada una no
 * esta en ninguna columna—. Son dos soportes distintos por eso: aquel escribe un
 * fichero *textfile* por ejecucion, este acumula en Redis peticion a peticion.
 *
 * ## Que responde esta metrica
 *
 * El objetivo «< 24 h desde la deteccion hasta la resolucion» del doc 01 §1.3 y
 * el cuadro de mando de la tarea 3.13. La etiqueta es el **tipo** y no la
 * severidad ni el responsable: lo que se ajusta con esta cifra son los umbrales
 * de deteccion —un tipo que siempre se descarta en cinco minutos esta mal
 * calibrado—, no el rendimiento de nadie. Una etiqueta por persona convertiria
 * un histograma en una evaluacion de desempeño sin retencion ni control de
 * acceso (regla dura 21, RGPD).
 */
interface IncidentResolutionMetrics
{
    /**
     * @param  int  $seconds  Segundos entre `detected_at` y la resolucion. Nunca negativo:
     *                        el dominio no admite una incidencia resuelta antes de detectarse.
     */
    public function resolutionObserved(IncidentType $type, int $seconds): void;
}
