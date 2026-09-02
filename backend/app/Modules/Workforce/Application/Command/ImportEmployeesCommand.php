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
    ) {}
}
