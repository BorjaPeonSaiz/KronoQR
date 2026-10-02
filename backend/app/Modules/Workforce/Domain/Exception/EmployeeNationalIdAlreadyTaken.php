<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Otro empleado ya tiene ese documento de identidad.
 *
 * El documento no se guarda —solo su digest (RL-08)— y es unico cuando existe
 * (`employees_national_id_hash_unique`, parcial sobre los no nulos). Antes de
 * la 2.2.0 el choque con ese indice salia como `500`; ahora es el `409` de
 * cualquier otro dato ya usado (bloque 17, alta contra importacion simultanea).
 */
final class EmployeeNationalIdAlreadyTaken extends WorkforceConflict
{
    public static function make(): self
    {
        // Sin el documento en el mensaje: el texto de una excepcion acaba en un
        // log, y el documento en claro no puede llegar a ninguno (RL-08).
        return new self('Ya existe otro empleado con ese documento de identidad.');
    }
}
