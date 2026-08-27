<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;

/**
 * Lo que el nucleo necesita saber de un empleado para decidir un fichaje
 * (RN-14, y el centro del que sale la zona horaria de RN-05).
 *
 * **Es la arista de ADR-025**: el puerto lo declara `Attendance`, que es quien
 * lo necesita, y lo implementa `Workforce`, que es quien tiene la tabla
 * `employees`. La dependencia va del satelite al nucleo y alcanza
 * `Attendance\Application\Port` y nada mas.
 *
 * Devuelve un objeto de valor de `Shared` y **nunca un modelo Eloquent**: es lo
 * que impide que el acoplamiento se cuele por el tipo de retorno.
 *
 * `displayName` sale del modelo de dominio para que la forma minima del nombre
 * —nombre de pila e inicial del primer apellido, §7.3— se decida en un solo
 * sitio. Un token de quiosco robado no debe permitir reconstruir la plantilla.
 */
final readonly class EloquentEmployeeDirectory implements EmployeeDirectory
{
    public function find(string $employeeUuid): ?EmployeeSnapshot
    {
        $row = Employee::query()
            ->select(['uuid', 'employee_code', 'first_name', 'last_name', 'status', 'site_id', 'department_id'])
            ->where('uuid', $employeeUuid)
            ->first();

        if (! $row instanceof Employee) {
            // `null` NO autoriza a bloquear a nadie en el quiosco (regla dura 19,
            // RN-15): quien decide que hacer con esto es el caso de uso.
            return null;
        }

        return new EmployeeSnapshot(
            employeeUuid: $row->uuid,
            employeeCode: $row->employee_code,
            displayName: $this->displayName($row),
            status: EmploymentStatus::from($row->status),
            siteId: $row->site_id,
            departmentId: $row->department_id,
        );
    }

    private function displayName(Employee $row): string
    {
        $initial = mb_substr(trim($row->last_name), 0, 1);

        return trim($row->first_name).' '.mb_strtoupper($initial).'.';
    }
}
