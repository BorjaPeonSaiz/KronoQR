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
}
