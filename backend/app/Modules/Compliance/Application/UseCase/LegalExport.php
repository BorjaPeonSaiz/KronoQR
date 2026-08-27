<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;

/**
 * El resultado de {@see GenerateLegalExport}: el fichero que ya existe en disco
 * y lo que lleva dentro.
 *
 * No es un objeto de dominio y por eso vive aqui: una ruta de fichero es un
 * detalle de la ejecucion. Lo que si es de dominio —el manifiesto y el
 * recuento— viaja dentro y es lo que se apunta en `audit_log` y lo que se
 * publica en las cabeceras de la respuesta.
 */
final readonly class LegalExport
{
    public function __construct(
        public LegalExportManifest $manifest,
        /** Ruta absoluta del fichero escrito. */
        public string $path,
        public LegalExportTally $tally,
    ) {}

    /** Nombre con el que se descarga. Nunca lleva el nombre de una persona. */
    public function filename(): string
    {
        return $this->manifest->filename();
    }
}
