<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Otro empleado ya tiene ese documento de identidad.
 *
 * El documento no se guarda —solo su digest (RL-08)— y es unico cuando existe
 * (`employees_national_id_hash_unique`, parcial sobre los no nulos). El choque
 * con ese indice —un alta contra una importacion simultanea— es el `409` de
 * cualquier otro dato ya usado, no un `500`.
 */
final class EmployeeNationalIdAlreadyTaken extends WorkforceConflict implements BusinessRejection
{
    public static function make(): self
    {
        // Sin el documento en el mensaje: el texto de una excepcion acaba en un
        // log, y el documento en claro no puede llegar a ninguno (RL-08).
        return new self('Ya existe otro empleado con ese documento de identidad.');
    }
}
