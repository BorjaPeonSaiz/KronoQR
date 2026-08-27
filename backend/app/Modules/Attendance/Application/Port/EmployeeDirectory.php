<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;

/**
 * Estado y adscripcion del empleado: lo que el nucleo necesita para decidir un
 * fichaje (RN-14, y el centro del que sale la zona horaria de RN-05).
 *
 * **Lo declara el nucleo y lo implementa `Workforce`** (ADR-025), que es donde
 * esta la tabla `employees`.
 *
 * Devuelve un objeto de valor inmutable de `Shared` y **nunca un modelo
 * Eloquent**: es lo que impide que el acoplamiento se cuele por el tipo de
 * retorno, que es la forma en que esta frontera se erosiona sin que ningun
 * `use` de Attendance lo delate.
 */
interface EmployeeDirectory
{
    /**
     * El empleado con ese UUID, o `null` si el padron no lo conoce.
     *
     * `null` **no autoriza a bloquear al empleado en el quiosco** (regla dura
     * 19, RN-15): el escaneo se registra igual y se genera una incidencia para
     * revision humana. Un padron cacheado desactualizado es un problema tecnico
     * ajeno a quien esta fichando.
     */
    public function find(string $employeeUuid): ?EmployeeSnapshot;
}
