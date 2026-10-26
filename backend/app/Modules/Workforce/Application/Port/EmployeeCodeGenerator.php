<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/**
 * Produce el codigo de un empleado nuevo (RF-GP-01, RF-ID-06).
 *
 * **Es un puerto por la misma razon que `Clock`** (regla dura 2): la
 * aleatoriedad es un efecto, y un dominio que la produce por su cuenta no se
 * puede probar. El dominio decide la forma del codigo
 * ({@see EmployeeCode::fromRandomPicks()}); el adaptador pone el azar.
 *
 * **El adaptador de produccion usa `random_int()` y ningun otro generador.**
 * `rand`, `mt_rand` y `Str::random` no son criptograficamente seguros: con
 * cualquiera de ellos, quien vea unos cuantos codigos puede predecir el
 * siguiente, y el codigo es la mitad publica de la credencial del portal.
 */
interface EmployeeCodeGenerator
{
    public function next(): EmployeeCode;
}
