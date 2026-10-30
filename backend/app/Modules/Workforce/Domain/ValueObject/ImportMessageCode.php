<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Por que se rechaza una linea, o que conviene saber de ella (**RF-GP-05**).
 *
 * ## Codigo cerrado, no texto libre
 *
 * El codigo es lo estable —el panel decide por el— y el texto es lo legible, que
 * vive en `lang/` y viaja ya traducido al idioma negociado. Con mensajes de
 * texto libre, cambiar una coma en una frase romperia la logica del panel, y
 * traducir el informe seria imposible.
 *
 * ## Cada codigo tiene una accion distinta detras
 *
 * Es el criterio de admision de esta lista: si dos motivos se arreglan igual, no
 * son dos codigos. `missing_identity` se arregla anadiendo una columna al
 * fichero; `unknown_department` se arregla creando el departamento **o**
 * corrigiendo la celda; `email_taken` se arregla mirando a quien pertenece ese
 * correo. Un unico `invalid_row` obligaria a llamar por telefono.
 */
enum ImportMessageCode: string
{
    /**
     * Ni documento de identidad ni correo.
     *
     * **Es el unico rechazo que no es un error del dato, sino de su
     * identificabilidad.** Sin una de las dos columnas no hay forma de reconocer
     * a esa persona si el fichero se vuelve a importar, y el resultado seria un
     * duplicado silencioso (regla dura 5). El correo sigue siendo opcional
     * (regla dura 12): lo que no puede faltar son **las dos**.
     */
    case MISSING_IDENTITY = 'missing_identity';

    case MISSING_FIRST_NAME = 'missing_first_name';
    case MISSING_LAST_NAME = 'missing_last_name';
    case MISSING_HIRED_AT = 'missing_hired_at';
    case INVALID_EMAIL = 'invalid_email';
    case INVALID_HIRED_AT = 'invalid_hired_at';
    case INVALID_NATIONAL_ID = 'invalid_national_id';

    /** El departamento de la celda no existe. Se crea antes, o se corrige la celda. */
    case UNKNOWN_DEPARTMENT = 'unknown_department';

    /**
     * La misma persona aparece dos veces **en el mismo fichero**.
     *
     * Se rechaza la segunda aparicion y no la primera. Aplicarlas las dos
     * dejaria el resultado a merced del orden de las filas, que es la clase de
     * comportamiento que nadie puede explicar despues.
     */
    case DUPLICATE_IN_FILE = 'duplicate_in_file';

    /** El correo ya es de **otra** persona. El indice unico parcial lo impide igualmente. */
    case EMAIL_TAKEN = 'email_taken';

    /**
     * Aviso: la fecha de alta del fichero no coincide con la guardada y **no se
     * aplica** (regla dura 5).
     *
     * Cambiar la fecha de alta de alguien mueve el punto desde el que corre su
     * conservacion (RL-02) y desde el que se le pueden imputar jornadas. Eso no
     * se hace de pasada en una importacion de cuarenta lineas: se hace en su
     * ficha, a conciencia. El aviso existe para que quien importa se entere en
     * vez de creer que se aplico.
     */
    case HIRED_AT_NOT_UPDATED = 'hired_at_not_updated';

    /**
     * La linea es de una persona **dada de baja** (2.2.0, RN-14, ADR-046 §5).
     *
     * La importacion no modifica la ficha de una baja ni la da de alta otra vez:
     * se rechaza la linea y el resto del fichero se aplica. Antes esa linea
     * llegaba a la modificacion, el dominio la rechazaba y tumbaba el lote entero
     * con un `409` que no nombraba ninguna linea. Se arregla quitando la linea
     * del fichero.
     */
    // Desde la 2.3.0 (RN-27) ya no se emite: la fila se propone como readmision.
    case EMPLOYEE_TERMINATED = 'employee_terminated';

    /**
     * Aviso de la fila `rehire` (RN-27, ADR-062 §6): la persona esta de baja y
     * se readmitira en su ficha al aplicar, con la fecha de alta de la fila.
     */
    case REHIRE_PROPOSED = 'rehire_proposed';

    /** La fila de una persona de baja no trae fecha de alta para la readmision. */
    case REHIRE_REQUIRES_HIRED_AT = 'rehire_requires_hired_at';

    /** La fecha de alta de la fila no es posterior a la fecha de cese de la persona. */
    case REHIRE_NOT_AFTER_TERMINATION = 'rehire_not_after_termination';

    /**
     * La fila encontro a una persona de baja, pero no con seguridad bastante
     * para readmitirla (RN-27): la coincidencia fue **solo por correo**, o el
     * nombre de la fila no es el de la ficha. Un correo compartido o una errata
     * en el documento readmitirian a otra persona en su ficha, y su registro
     * horario seguiria a nombre de quien no es. Se rechaza la linea; la
     * readmision se hace desde la ficha o con el documento en el fichero.
     */
    case REHIRE_IDENTITY_MISMATCH = 'rehire_identity_mismatch';

    /**
     * Aviso de la fila `rehire`: trae un nombre o un correo distintos de los de
     * la ficha, y la readmision **no** los aplica (nombre, documento y codigo no
     * cambian al readmitir). Si hay que cambiarlos, se hace en la ficha.
     */
    case REHIRE_IGNORES_IDENTITY_CHANGES = 'rehire_ignores_identity_changes';

    /**
     * Aviso: el fichero trae una columna que el importador no usa.
     *
     * No rechaza nada —una exportacion de nomina trae veinte columnas y a
     * nosotros nos interesan siete— pero se dice, porque el caso que importa es
     * el otro: que alguien haya escrito `e-mail` donde el mapa espera `email` y
     * crea que ha importado los correos.
     */
    case UNKNOWN_COLUMN = 'unknown_column';

    /**
     * Aviso: el idioma de la linea no esta activo en la instalacion
     * (`LOCALE_AVAILABLE`; bloque 26 de la 2.2.1).
     *
     * No rechaza la linea: el alta toma el idioma de la instalacion y una ficha
     * que ya existe conserva el suyo. Se arregla activando ese idioma en la
     * configuracion, si de verdad se quiere ofrecer, o quitando la columna.
     */
    case LOCALE_NOT_ACTIVE = 'locale_not_active';

    /** Un aviso no impide la linea; un error si. */
    public function isWarning(): bool
    {
        return match ($this) {
            self::HIRED_AT_NOT_UPDATED, self::UNKNOWN_COLUMN, self::LOCALE_NOT_ACTIVE, self::REHIRE_PROPOSED,
            self::REHIRE_IGNORES_IDENTITY_CHANGES => true,
            default => false,
        };
    }
}
