<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use DateTimeImmutable;

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

    /**
     * El instante en que se hizo efectiva la baja del periodo en curso de cada
     * persona (`Employee.offboarding.offboarded_at`, ADR-062 §1 y §4), en una
     * sola consulta para toda una pagina del listado. Solo aparecen las
     * personas con la baja efectiva.
     *
     * @param  list<string>  $employeeUuids
     * @return array<string, DateTimeImmutable> indexado por `employee_uuid`
     */
    public function offboardedAtOf(array $employeeUuids): array;
}
