<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/**
 * El estado laboral de un empleado por su clave interna (RN-14).
 *
 * Existe porque `Identity` tiene que negarse a emitir una tarjeta a una persona
 * de baja y el dato vive en `employees`, que es de `Workforce`: dos satelites que
 * no pueden importarse (doc 02 §1.6). Es el mismo caso que
 * {@see EmployeeRegistry}, que se declara a proposito «de dos metodos y ni uno
 * mas» —solo traduce identificadores—, y por eso esto es un puerto aparte y no
 * un tercer metodo alli.
 *
 * **No decide si alguien puede fichar**: eso sigue siendo
 * `EmploymentStatus::canClock()` sobre el `EmployeeSnapshot` de `Attendance`.
 * Aqui solo se devuelve el estado; la regla la aplica el dominio de quien
 * pregunta.
 */
interface EmploymentStatusLookup
{
    /** Estado laboral del empleado con esa clave interna, o `null` si no existe. */
    public function statusOf(int $employeeId): ?EmploymentStatus;

    /**
     * El estado de varios empleados **en una sola consulta**, indexado por su
     * clave interna. Los que no existen no aparecen.
     *
     * Existe para la rotacion de la clave de firma, que decide sobre cada
     * tarjeta con la cadena de `audit_log` tomada (ADR-046 §1.2): una consulta
     * por tarjeta alargaba el candado que comparten todos los fichajes.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, EmploymentStatus>
     */
    public function statusesOf(array $employeeIds): array;
}
