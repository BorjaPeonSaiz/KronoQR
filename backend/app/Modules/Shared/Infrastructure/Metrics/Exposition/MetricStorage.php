<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics\Exposition;

/**
 * Como esta guardada en Redis cada serie del catalogo.
 *
 * No es un detalle que se pueda deducir del tipo: los catorce adaptadores
 * `Redis*Metrics` de las Fases 1 a 5 escriben con **tres** formas distintas, y
 * las tres son correctas para lo que hace cada una. Reescribirlas para
 * unificarlas habria tocado catorce clases, sus pruebas y el paquete de
 * diagnostico para acabar exponiendo exactamente lo mismo (decision 1 de la
 * ficha 3.1). Lo que hace el catalogo es **declarar** cual usa cada serie, para
 * que el lector no tenga que adivinarlo probando comandos.
 */
enum MetricStorage
{
    /**
     * Un unico HASH en `kronoqr:metrics:<serie>`, con la combinacion de
     * etiquetas como campo: `HINCRBY kronoqr:metrics:scans_total
     * "device=…,result=clock_in" 1`.
     *
     * Es la forma mayoritaria: un solo viaje a Redis por medicion y una sola
     * clave por serie, que es lo que quiere el camino de fichaje.
     */
    case LabelledHash;

    /**
     * Una clave suelta por combinacion de etiquetas,
     * `kronoqr:metrics:<serie>:<etiqueta>=<valor>`, con `INCRBY`.
     *
     * La usan las series cuya etiqueta tiene dos o tres valores posibles y que
     * se escriben desde sitios donde `INCRBY` es lo unico que hacia falta.
     */
    case ScalarKeys;

    /**
     * `sum`, `count`, `le=<cubo>` y `le=+Inf` como campos de un hash. Sin
     * etiquetas, el hash es `kronoqr:metrics:<serie>`; con etiquetas, hay un
     * hash por combinacion en `kronoqr:metrics:<serie>:<etiquetas>`.
     */
    case Histogram;

    /**
     * No esta en Redis: se calcula **en el momento del scrape**.
     *
     * Solo `queue_jobs_pending{queue}`, y es deliberado: la profundidad de una
     * cola es un ESTADO, no un acontecimiento. Un contador acumulado no puede
     * responder «cuantos trabajos hay ahora esperando», que es la unica
     * pregunta que esa serie contesta.
     */
    case Runtime;
}
