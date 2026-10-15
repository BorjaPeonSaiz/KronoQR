<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

/**
 * Lo que paso al pedir la correccion de la zona horaria del centro (RN-23,
 * ADR-056 §3). Solo `Corrected` escribe algo.
 */
enum SiteTimezoneCorrectionOutcome
{
    /** La zona se corrigio y quedo el asiento `site.updated`. */
    case Corrected;

    /** La instalacion no tiene centro: la zona se elige en la puesta en marcha. */
    case NoSite;

    /** El centro ya tiene esa zona: no hay nada que corregir. */
    case SameTimezone;

    /** No hay registro horario: la zona se cambia desde el panel, no por consola. */
    case NoWorkRecord;
}
