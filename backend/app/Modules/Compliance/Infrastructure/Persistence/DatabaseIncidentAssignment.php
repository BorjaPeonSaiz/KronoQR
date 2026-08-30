<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\IncidentAssignment;
use Illuminate\Database\ConnectionInterface;

/**
 * Quien responde del departamento de un empleado (`departments.manager_user_id`,
 * doc 01 §5.5).
 *
 * **Tres motivos para devolver `null`, y ninguno descarta la incidencia**
 * (RF-PR-01): el empleado no tiene departamento, el departamento no tiene
 * responsable, o la cuenta del responsable esta desactivada. En los tres casos la
 * incidencia se abre **sin asignar** y sigue visible en la bandeja de quien tenga
 * alcance sobre ella. Perder un hallazgo por un hueco de configuracion seria lo
 * contrario de lo que la deteccion existe para hacer.
 *
 * **La cuenta desactivada importa mas de lo que parece.** Asignar a alguien que ya
 * no entra al panel es la forma silenciosa de que una incidencia no la vea nadie:
 * figura asignada, nadie recibe el aviso y no aparece en la bandeja de ninguna
 * persona activa.
 */
final readonly class DatabaseIncidentAssignment implements IncidentAssignment
{
    public function __construct(private ConnectionInterface $connection) {}

    public function responsibleFor(string $employeeUuid): ?int
    {
        $managerId = $this->connection->table('employees')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->join('users', 'users.id', '=', 'departments.manager_user_id')
            ->where('employees.uuid', $employeeUuid)
            ->where('users.is_active', true)
            ->value('users.id');

        return is_numeric($managerId) ? (int) $managerId : null;
    }
}
