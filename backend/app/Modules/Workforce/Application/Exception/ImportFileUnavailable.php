<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Exception;

use RuntimeException;

/**
 * El fichero de importacion subido no se puede leer **del disco** (**RF-GP-05**):
 * `hash_file`, `fopen` o `file_get_contents` fallan sobre el temporal que acaba
 * de recibir el servidor.
 *
 * ## Por que no es `UnreadableImportFile`
 *
 * `UnreadableImportFile` es un **rechazo**: el contenido no se entiende —un
 * XLSX corrupto, un CSV sin cabecera— y quien lo sube puede arreglarlo
 * exportandolo otra vez (`422` en `file`). Aqui el fichero puede estar bien y lo
 * que falla es el servidor: el temporal ha desaparecido, no hay permisos, el
 * disco da error de E/S. Decirle a quien lo sube que vuelva a exportar un
 * fichero correcto le haria perder el tiempo, y el fallo no llegaria nunca al
 * historico de errores. Es una **averia**: sin el marcador `BusinessRejection`,
 * sale `500` y queda en el log y en `error_events`.
 */
final class ImportFileUnavailable extends RuntimeException
{
    public static function fromDisk(): self
    {
        return new self('The import file cannot be read from disk.');
    }
}
