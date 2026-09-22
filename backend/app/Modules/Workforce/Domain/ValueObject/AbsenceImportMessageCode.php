<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Por que se rechaza una linea del fichero de ausencias, o que conviene saber de
 * ella (**RF-GP-04**).
 *
 * ## Codigo cerrado, no texto libre
 *
 * El codigo es lo estable —el panel decide por el— y el texto es lo legible, que
 * vive en `lang/` y viaja ya traducido al idioma negociado. Mismo criterio que
 * {@see ImportMessageCode}.
 *
 * ## Cada codigo tiene una accion distinta detras
 *
 * Es el criterio de admision de esta lista: si dos motivos se arreglan igual, no
 * son dos codigos. `unknown_employee` se arregla mirando el codigo en la ficha;
 * `unknown_type` corrigiendo la celda; `overlapping_absence` revisando el
 * historial de esa persona; `duplicate_in_file` borrando una de las dos lineas.
 * Un unico `invalid_row` obligaria a llamar por telefono.
 *
 * ## Y nunca el valor de la celda
 *
 * Ni el codigo de empleado en el mensaje, ni la nota, ni el tipo. El informe ya
 * lleva el numero de linea y la columna, y quien lo arregla tiene el fichero
 * delante (regla dura 21).
 */
enum AbsenceImportMessageCode: string
{
    /** La linea no dice de quien es. Sin eso no hay a quien registrarsela. */
    case MISSING_EMPLOYEE_CODE = 'missing_employee_code';

    /**
     * No hay ninguna persona con ese codigo.
     *
     * Se arregla mirando el codigo en su ficha. **No se empareja por nombre**:
     * dos personas se llaman igual con mas frecuencia de lo que parece en un
     * hotel de temporada, y atribuir una baja medica a quien no es seria el peor
     * desenlace posible de este endpoint.
     */
    case UNKNOWN_EMPLOYEE = 'unknown_employee';

    case MISSING_TYPE = 'missing_type';

    /** La celda no casa con ninguna categoria ni con ninguno de sus alias castellanos. */
    case UNKNOWN_TYPE = 'unknown_type';

    case MISSING_STARTS_ON = 'missing_starts_on';
    case MISSING_ENDS_ON = 'missing_ends_on';
    case INVALID_STARTS_ON = 'invalid_starts_on';
    case INVALID_ENDS_ON = 'invalid_ends_on';

    /** La ausencia terminaria antes de empezar. */
    case INVERTED_PERIOD = 'inverted_period';

    /** El tipo `other` sin nota: sin texto no describe nada. */
    case NOTE_REQUIRED = 'note_required';

    /**
     * La nota pasa del techo de la columna.
     *
     * **Se comprueba aqui y no solo en el `CHECK`**, y la revision de seguridad
     * de la 3.10 explica por que: sin este codigo, una celda de 501 caracteres
     * pasaba la fase de comprobacion como `create` y reventaba al aplicar contra
     * `absences_chk_note_length`. El mensaje de esa `QueryException` lleva los
     * **valores enlazados** —la nota entera y el tipo— y acababa escrito en
     * `storage/logs`, que no esta saneado. Es decir: un diagnostico medico en un
     * fichero de texto (regla dura 21, art. 9 del RGPD).
     */
    case NOTE_TOO_LONG = 'note_too_long';

    /**
     * Pisa una ausencia **activa ya registrada** de esa persona.
     *
     * **No es lo mismo que una reimportacion**: una linea identica —mismo tipo
     * y mismas dos fechas— sale como {@see AbsenceImportOutcome::UNCHANGED} y no
     * lleva este codigo. Solo se rechaza lo que pisa **sin** coincidir.
     */
    case OVERLAPPING_ABSENCE = 'overlapping_absence';

    /**
     * Dos lineas del **mismo fichero** se pisan.
     *
     * Se rechaza la segunda aparicion y no la primera. Aplicarlas las dos dejaria
     * el resultado a merced del orden de las filas, que es la clase de
     * comportamiento que nadie puede explicar despues.
     */
    case DUPLICATE_IN_FILE = 'duplicate_in_file';

    /**
     * Aviso: el fichero trae una columna que el importador no usa.
     *
     * No rechaza nada, pero se dice, porque el caso que importa es el otro: que
     * alguien haya escrito `observaciones` donde el mapa espera `nota` y crea que
     * ha cargado las notas.
     */
    case UNKNOWN_COLUMN = 'unknown_column';

    /** Un aviso no impide la linea; un error si. */
    public function isWarning(): bool
    {
        return $this === self::UNKNOWN_COLUMN;
    }
}
