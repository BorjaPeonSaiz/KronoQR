<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Que paso al intentar borrar una entrada de una clase de fichero (ADR-045, C3).
 */
enum GeneratedFileRemoval
{
    /** Borrada. */
    case Removed;

    /** Ya no estaba: la borro otro, o nunca existio. No es un error. */
    case Absent;

    /**
     * **No se ha tocado nada a proposito**: la ruta cae fuera de la raiz de su
     * clase, el nombre no casa con el patron, es un enlace simbolico, o es un
     * directorio con un subdirectorio o un enlace dentro. Deja log tecnico y
     * sube `generated_files_refused_total{class}`.
     */
    case Refused;

    /** El sistema de ficheros nego el borrado (permisos, solo lectura). Se reintenta en la siguiente pasada. */
    case Failed;
}
