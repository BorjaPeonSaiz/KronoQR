<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Que clase de fila es cada linea de la exportacion legal (RF-IN-05, RL-04).
 *
 * **Dos tipos de fila en una sola tabla, y no dos ficheros.** Un requerimiento
 * de Inspeccion se contesta con un documento, no con un juego de documentos que
 * el inspector tenga que cruzar. Y una sola tabla con una columna de tipo se
 * filtra en cualquier hoja de calculo sin saber nada del sistema, que es lo que
 * RL-06 quiere decir con «tratable».
 *
 * **El valor es un codigo en ingles y no el texto que se imprime** (doc 02
 * §3.5): lo que lee el inspector sale de `lang/{es,en}/legal-export.php`, porque
 * el idioma del documento es configuracion de la instalacion y no una constante
 * del programa (regla dura 13, ADR-017).
 */
enum LegalExportRecordType: string
{
    /** Un periodo de trabajo: entrada, salida y duracion (RN-05, regla dura 4). */
    case ShiftEntry = 'shift_entry';

    /**
     * Una rectificacion del registro, con autor, momento y motivo (RN-13,
     * RL-04). Ocultarlas incumple: son justo lo que una inspeccion viene a
     * mirar.
     */
    case Correction = 'correction';
}
