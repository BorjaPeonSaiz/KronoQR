<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El estado de un tramo tal y como se entrega a la Inspeccion (RF-IN-05,
 * RL-04, RL-06; H5 de la verificacion de la 2.1.0).
 *
 * **Cuatro y no cinco.** `superseded` es la version anterior de un tramo
 * corregido: no es un periodo de trabajo y no se exporta como fila de tramo,
 * sino como el «antes» de su fila de correccion. Que el tipo no lo admita es lo
 * que impide que una consulta mal filtrada lo cuele como un tramo mas.
 *
 * Los valores son los de `shift_entries.status`. `Compliance` no puede importar
 * el enumerado de `Attendance` (doc 02 §1.6), asi que los repite;
 * `ExportedShiftEntryTest` comprueba que no divergen.
 */
enum ExportedShiftEntryStatus: string
{
    /** Todavia sin salida: no tiene duracion, que no es lo mismo que cero. */
    case Open = 'open';

    case Closed = 'closed';

    /** Cerrado con sus marcas reales, pendiente de que alguien lo revise (RN-07, RN-08). */
    case Anomalous = 'anomalous';

    /** Anulado: figura en el documento —nada se oculta— pero no suma horas. */
    case Voided = 'voided';
}
