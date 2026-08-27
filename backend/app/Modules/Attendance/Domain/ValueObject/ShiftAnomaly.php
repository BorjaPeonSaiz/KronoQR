<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Lo que tiene de raro la duracion de un tramo recien cerrado.
 *
 * Los valores respaldados son los de `incidents.type` (doc 01 §5.5) para que
 * Compliance pueda abrir la incidencia al recibir `EmployeeClockedOut` **sin
 * volver a calcular nada ni conocer los umbrales**: Attendance emite y ellos
 * reaccionan (doc 02 §1.6).
 *
 * Un `enum` y no un booleano `anomalo`: un booleano obliga a quien reacciona a
 * recalcular cual de las dos reglas salto, y a tener otra vez los umbrales
 * delante para hacerlo.
 */
enum ShiftAnomaly: string
{
    /** RN-07: por debajo de la duracion minima computable. Se registra igual, y se marca para revision. */
    case SHORT_SHIFT = 'short_shift';

    /** RN-08: por encima de la duracion anomala. **Nunca se cierra solo**; ya esta cerrado, y lo revisa una persona. */
    case LONG_SHIFT = 'long_shift';
}
