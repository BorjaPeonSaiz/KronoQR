<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * El fichero de ausencias y que hacer con el (**RF-GP-04**).
 *
 * Calcado de {@see ImportEmployeesCommand} y por las mismas razones, que estan
 * escritas alli: el fichero **no se guarda** entre las dos fases y la
 * confirmacion viaja como huella, no como identificador de algo almacenado.
 */
final readonly class ImportAbsencesCommand
{
    public function __construct(
        /** Ruta del temporal de la peticion. PHP lo borra al terminar. */
        public string $path,
        /** `false` es el modo simulacion de la decision 5: no escribe nada. */
        public bool $apply,
        /** El `sha256` que devolvio la validacion. Obligatorio con `apply`. */
        public ?string $confirmChecksum = null,
        public ?int $importedByUserId = null,
    ) {}
}
