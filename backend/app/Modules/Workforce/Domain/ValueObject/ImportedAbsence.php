<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Una linea del fichero de ausencias ya traducida a los campos del producto,
 * **pero todavia sin validar** (**RF-GP-04**).
 *
 * Los cinco campos son cadenas tal y como venian en las celdas: la fecha puede
 * no ser una fecha y el tipo puede no existir. Convertirlos es del planificador
 * de la carga, que vive en la capa de aplicacion y es quien puede decir en que
 * linea y en que columna esta el problema.
 *
 * **Ese planificador no se nombra aqui con su clase**, ni siquiera en un
 * `@see`: el dominio no conoce la capa de aplicacion (regla dura 1) y Deptrac
 * cuenta una referencia de docblock como lo que es —lo que precede a un `use`—.
 *
 * **Sin el nombre de nadie.** La persona viaja por su codigo de empleado, que es
 * lo unico estable y publico que el fichero puede traer: el nombre se repite y
 * el documento de identidad no se almacena (RL-08).
 */
final readonly class ImportedAbsence
{
    public function __construct(
        public string $employeeCode,
        public string $type,
        public string $startsOn,
        public string $endsOn,
        public ?string $note = null,
    ) {}
}
