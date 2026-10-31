<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * La historia laboral de una persona para la ficha de `rrhh+` (RN-27, RN-13,
 * regla dura 5): sus periodos de alta con su baja efectiva y las correcciones
 * de su fecha de cese.
 *
 * **Una sola lectura acotada por persona**, sin N+1: la ficha la pide una vez y
 * el coste no crece con el numero de periodos ni de correcciones.
 */
interface EmploymentRecordReader
{
    /**
     * Del periodo mas antiguo al mas reciente; las correcciones de cada uno,
     * en orden cronologico. Lista vacia para un `uuid` que no existe.
     *
     * @return list<EmploymentPeriodRecord>
     */
    public function periodsOf(string $employeeUuid): array;
}
