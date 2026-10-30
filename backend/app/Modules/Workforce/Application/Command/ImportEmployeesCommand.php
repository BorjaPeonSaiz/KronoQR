<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Importacion masiva de plantilla (RF-GP-05).
 *
 * `path` es la ruta del fichero **ya subido**, en el directorio temporal de la
 * peticion: se lee en streaming desde ahi y desaparece al terminar. El producto
 * **no lo guarda** entre la validacion y la aplicacion — la confirmacion viaja
 * como `confirmChecksum` y el fichero se vuelve a subir.
 *
 * `apply` distingue las dos fases. Con `false` no se escribe una sola fila: es
 * el modo simulacion que exige RF-GP-05.
 */
final readonly class ImportEmployeesCommand
{
    public function __construct(
        public string $path,
        public bool $apply,
        public ?string $confirmChecksum,
        /** La cuenta de gestion que importa: autora de las readmisiones del lote (RN-27). */
        public ?int $importedByUserId = null,
        /**
         * El `rehire_digest` que devolvio la validacion, o `null` si no lo
         * devolvio: las readmisiones que se aplican son las revisadas (RN-27).
         */
        public ?string $confirmRehireDigest = null,
    ) {}
}
