<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics\Exposition;

/**
 * Los tres tipos de metrica que el producto expone por `/metrics` (doc 02 §8.2).
 *
 * No hay `summary` a proposito: ningun adaptador del producto escribe cuantiles
 * precalculados, y un `summary` no se puede agregar entre instancias. Todo lo
 * que mide una duracion es un histograma con cubos declarados.
 *
 * El valor respaldado es literalmente lo que va detras de `# TYPE`.
 */
enum MetricType: string
{
    case Counter = 'counter';

    case Gauge = 'gauge';

    case Histogram = 'histogram';
}
