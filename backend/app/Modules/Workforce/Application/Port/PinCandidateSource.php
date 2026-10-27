<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use Random\RandomException;

/**
 * El azar de un PIN candidato (RF-ID-09).
 *
 * **Es un puerto por la misma razon que {@see EmployeeCodeGenerator}**: la
 * aleatoriedad es un efecto, y un generador que la produce por su cuenta no se
 * puede probar donde importa. Lo que hay que demostrar del generador es que
 * descarta un PIN excluido y vuelve a intentarlo, y eso solo se ve si la
 * prueba decide que candidatos salen.
 *
 * **El adaptador de produccion usa `random_int()` y ningun otro generador.** El
 * PIN es la llave del registro horario personal (RL-05) y del fichaje de
 * respaldo (RF-AT-11): con `rand`, `mt_rand` o `Str::random`, quien vea unos
 * cuantos PIN puede predecir el siguiente.
 */
interface PinCandidateSource
{
    /**
     * Un entero uniforme entre `0` y `$maximum`, los dos incluidos.
     *
     * @throws RandomException si el sistema no puede dar aleatoriedad: es
     *                         preferible no emitir un PIN que emitir uno adivinable
     */
    public function pick(int $maximum): int;
}
