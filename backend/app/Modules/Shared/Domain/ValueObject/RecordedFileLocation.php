<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Donde esta el fichero que una fila dice tener (ADR-045 §d, C3, C5).
 *
 * Es la mitad «fila → fichero» de la conciliacion: una fila `completed` cuyo
 * fichero no esta (`Missing`) pasa a `purged`, y si aun no habia caducado deja
 * ademas el asiento `*.file_missing`. Una fila que apunta fuera de la raiz de su
 * clase (`OutsideArea`) pasa a `purged` **sin borrar nada**: puede ser una fila
 * alterada que pretende que la purga borre una copia de `BACKUP_PATH`.
 */
enum RecordedFileLocation
{
    /** Dentro de su raiz, con su nombre de clase, y es un fichero regular. */
    case Present;

    /** Dentro de su raiz, pero ya no esta. */
    case Missing;

    /** Fuera de su raiz, con un nombre ajeno a su clase, o a traves de un enlace simbolico. */
    case OutsideArea;
}
