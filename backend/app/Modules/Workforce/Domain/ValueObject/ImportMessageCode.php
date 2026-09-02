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
     * Aviso: el fichero trae una columna que el importador no usa.
     *
     * No rechaza nada —una exportacion de nomina trae veinte columnas y a
     * nosotros nos interesan siete— pero se dice, porque el caso que importa es
     * el otro: que alguien haya escrito `e-mail` donde el mapa espera `email` y
     * crea que ha importado los correos.
     */
    case UNKNOWN_COLUMN = 'unknown_column';

    /** Un aviso no impide la linea; un error si. */
    public function isWarning(): bool
    {
        return $this === self::HIRED_AT_NOT_UPDATED || $this === self::UNKNOWN_COLUMN;
    }
}
