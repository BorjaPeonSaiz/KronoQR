<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * El nombre, el departamento y el centro que se imprimen en una tarjeta
 * (RF-QR-04), y la plantilla de alta que cuenta el panel de RF-QR-08.
 *
 * **Es una arista de ADR-025.** El puerto lo declara `Shared` porque lo consume
 * `Identity` y lo implementa este modulo, que es donde estan `employees`,
 * `departments` y `sites`. `Identity` nunca consulta esas tres tablas: pregunta
 * aqui.
 *
 * **Una sola consulta con dos `LEFT JOIN`, no N+1.** La impresion por lotes de un
 * centro completo son 60 empleados de la semilla y varios cientos en una cadena;
 * resolver el nombre del departamento fila a fila convertiria una operacion de
 * una consulta en cientos, dentro del mismo minuto en que alguien esta esperando
 * un PDF. El `JOIN` es a la izquierda porque `employees.department_id` es
 * opcional: hay personal sin departamento y su tarjeta se imprime igual.
 *
 * **«De alta» significa `EmploymentStatus::canClock()`** y no «no terminado». La
 * metrica `employees_without_delivered_credential` existe para llegar a cero
 * antes de cada incorporacion (doc 02 §8.2); contar a quien esta suspendido
 * —que por RN-14 no ficha aunque tenga tarjeta— la dejaria permanentemente por
 * encima de cero y por tanto sin capacidad de alertar de nada.
 *
 * **El nombre completo, no la forma minima del §7.3.** Es la diferencia con
 * {@see EloquentEmployeeDirectory}: aquel sirve al padron cacheado de una tablet
 * colgada de una pared, donde el apellido entero es superficie de fuga; este
 * sirve a la tarjeta que se le entrega en mano a su titular y al panel de RRHH,
 * donde un «Lucia M.» no distingue entre dos personas de la misma cocina.
 */
final readonly class EloquentEmployeeCardDirectory implements EmployeeCardDirectory
{
    public function activeProfiles(?int $siteId = null, ?string $employeeUuid = null): array
    {
        $query = $this->baseQuery()
            ->where('employees.status', EmploymentStatus::ACTIVE->value)
            // Orden estable y con sentido fisico: las tarjetas salen de la hoja
            // A4 agrupadas por centro y por departamento, que es como se
            // reparten. `employees.id` al final rompe empates para que dos
            // ejecuciones produzcan la misma hoja.
            ->orderBy('sites.name')
            ->orderByRaw('departments.name NULLS LAST')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('employees.id');

        if ($siteId !== null) {
            $query->where('employees.site_id', $siteId);
        }

        if ($employeeUuid !== null) {
            // La comparacion la resuelve el tipo `uuid` nativo de PostgreSQL,
            // que normaliza el literal: `0199F0C2-...` y `0199f0c2-...` son el
            // mismo valor. Filtrar despues en PHP con `===` devolvia vacio para
            // el primero, porque la columna se lee siempre en minusculas.
            $query->where('employees.uuid', $employeeUuid);
        }

        /** @var list<EmployeeCardProfile> $profiles */
        $profiles = $query->get()
            ->map(fn (Employee $row): EmployeeCardProfile => $this->toProfile($row))
            ->values()
            ->all();

        return $profiles;
    }

    public function profileFor(int $employeeId): ?EmployeeCardProfile
    {
        $row = $this->baseQuery()->where('employees.id', $employeeId)->first();

        return $row instanceof Employee ? $this->toProfile($row) : null;
    }

    /**
     * @return Builder<Employee>
     */
    private function baseQuery(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.uuid',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.site_id',
                'sites.name as site_name',
                'departments.name as department_name',
            ])
            ->join('sites', 'sites.id', '=', 'employees.site_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id');
    }

    /**
     * `site_name` y `department_name` vienen de los `JOIN` y no son atributos
     * declarados del modelo, asi que se leen con `getAttribute()` y se convierten
     * explicitamente. Lo que sale de aqui es un objeto de valor de `Shared`,
     * nunca un modelo Eloquent (ADR-025, restriccion 2).
     */
    private function toProfile(Employee $row): EmployeeCardProfile
    {
        $site = $row->getAttribute('site_name');
        $department = $row->getAttribute('department_name');

        return new EmployeeCardProfile(
            employeeUuid: $row->uuid,
            employeeCode: $row->employee_code,
            fullName: trim($row->first_name.' '.$row->last_name),
            siteName: \is_string($site) ? $site : '',
            siteId: $row->site_id,
            departmentName: \is_string($department) ? $department : null,
            employeeId: $row->id,
        );
    }
}
