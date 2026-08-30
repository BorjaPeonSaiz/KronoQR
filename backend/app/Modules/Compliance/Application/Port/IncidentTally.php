<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Cuantas incidencias hay abiertas de un tipo y una severidad (doc 02 §8.2).
 *
 * Es la unidad del gauge `incidents_open{type,severity}` y por eso lleva los dos
 * enums y no cadenas: las etiquetas de una metrica son un catalogo cerrado, y una
 * cadena libre ahi es como acaba explotando la cardinalidad de una serie.
 *
 * **No lleva a nadie dentro.** Es un recuento; el detalle esta en la bandeja,
 * que se lee con autorizacion (regla dura 21).
 */
final readonly class IncidentTally
{
    public function __construct(
        public IncidentType $type,
        public IncidentSeverity $severity,
        public int $open,
    ) {}
}
