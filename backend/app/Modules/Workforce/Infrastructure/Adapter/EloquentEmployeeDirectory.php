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
    private const array COLUMNS = ['uuid', 'employee_code', 'first_name', 'last_name', 'status', 'site_id', 'department_id', 'hired_at', 'terminated_at'];

    public function find(string $employeeUuid): ?EmployeeSnapshot
    {
        return $this->snapshotOf(
            Employee::query()->select(self::COLUMNS)->where('uuid', $employeeUuid)->first(),
        );
    }

    public function findByCode(string $employeeCode): ?EmployeeSnapshot
    {
        // La misma consulta, exista o no el codigo (F5): un acceso al indice
        // UNIQUE de `employee_code` y nada mas.
        return $this->snapshotOf(
            Employee::query()->select(self::COLUMNS)->where('employee_code', $employeeCode)->first(),
        );
    }

    private function snapshotOf(?Employee $row): ?EmployeeSnapshot
    {
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
            // Fechas civiles tal cual estan en la columna `date`: el alta manual
            // de tramos acota con ellas las jornadas de una baja (RN-14, 2.2.0).
            hiredOn: $row->hired_at->format('Y-m-d'),
            terminatedOn: $row->terminated_at?->format('Y-m-d'),
        );
    }

    private function displayName(Employee $row): string
    {
        $initial = mb_substr(trim($row->last_name), 0, 1);

        return trim($row->first_name).' '.mb_strtoupper($initial).'.';
    }
}
