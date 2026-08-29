<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\EmployeeScopeDirectory;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;

/**
 * El departamento de un empleado, sobre la tabla que lo tiene (RF-ID-03).
 *
 * **Es una arista de ADR-025 y de las mas estrechas del sistema**, como
 * {@see EloquentEmployeeRegistry}: el puerto lo declara `Shared` porque lo
 * consumen `Reporting` y `Attendance`, y lo implementa este modulo, que es quien
 * posee `employees`.
 *
 * **Se lee una sola columna** y no la fila entera. No es tacaneria: esto se
 * consulta antes de autorizar, y traer el nombre y el correo de alguien para
 * decidir si se le puede mirar el registro horario pondria datos personales en
 * memoria de un componente que no tiene ninguna razon para verlos. Lo que no se
 * carga no se puede filtrar a un log por descuido (regla dura 21).
 */
final readonly class EloquentEmployeeScopeDirectory implements EmployeeScopeDirectory
{
    public function departmentIdOf(string $employeeUuid): ?int
    {
        /** @var int|null $departmentId */
        $departmentId = Employee::query()
            ->where('uuid', $employeeUuid)
            ->value('department_id');

        return $departmentId;
    }
}
