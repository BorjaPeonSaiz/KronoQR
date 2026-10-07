<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\DepartmentView;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department as DepartmentEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Los departamentos sobre Eloquent.
 *
 * La unicidad del nombre es **por centro** (`departments_site_id_name_unique`),
 * no global: dos hoteles del mismo cliente tienen los dos una «Recepcion».
 *
 * **El responsable se lee con un `LEFT JOIN` a `users`** por nombre de tabla y
 * columnas, sin el modelo de `Identity` (doc 02 §1.6, Deptrac): la misma
 * licencia que ya se toman `Compliance` y `Product` con esa tabla. Una sola
 * consulta para el listado, sin N+1, y sin filtrar por `is_active`: una cuenta
 * de baja sigue saliendo y es el panel quien lo señala.
 */
final readonly class EloquentDepartmentRepository implements DepartmentRepository
{
    public function add(DepartmentEntity $department): DepartmentEntity
    {
        try {
            $row = Department::query()->create([
                'site_id' => $department->siteId,
                'name' => $department->name,
            ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception, $department->name);
        }

        return $department->withId($row->id);
    }

    public function save(DepartmentEntity $department): void
    {
        if ($department->id === null) {
            throw new RuntimeException('No se puede actualizar un departamento que todavia no se ha guardado.');
        }

        try {
            Department::query()->whereKey($department->id)->update([
                'name' => $department->name,
            ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception, $department->name);
        }
    }

    public function findById(int $id): ?DepartmentEntity
    {
        $row = Department::query()->find($id);

        return $row instanceof Department ? $this->toEntity($row) : null;
    }

    public function all(): array
    {
        $rows = Department::query()
            ->orderBy('name')
            ->get();

        return array_values(array_map($this->toEntity(...), $rows->all()));
    }

    /**
     * `FOR UPDATE` y no `FOR NO KEY UPDATE`: `name` esta en el indice unico
     * completo `departments_site_id_name_unique`, asi que el `UPDATE` de un
     * renombrado sube a `FOR UPDATE` por su cuenta. Tomado aqui, **antes** de la
     * cadena (ADR-046 §1.1 punto 3), el renombrado no lo sube con la cadena en
     * la mano. Siempre, aunque solo cambie el responsable: es barato y es el
     * candado que pide el ADR.
     */
    public function findForUpdate(int $id): ?DepartmentEntity
    {
        $row = Department::query()->whereKey($id)->lockForUpdate()->first();

        return $row instanceof Department ? $this->toEntity($row) : null;
    }

    public function findView(int $id): ?DepartmentView
    {
        $row = $this->withManager()->where('departments.id', $id)->first();

        return $row instanceof Department ? $this->toView($row) : null;
    }

    public function allViews(): array
    {
        $rows = $this->withManager()
            ->orderBy('departments.name')
            ->get();

        return array_values(array_map($this->toView(...), $rows->all()));
    }

    public function assignManager(int $departmentId, ?int $managerUserId): void
    {
        Department::query()->whereKey($departmentId)->update([
            'manager_user_id' => $managerUserId,
        ]);
    }

    /**
     * @return Builder<Department>
     */
    private function withManager(): Builder
    {
        return Department::query()
            ->leftJoin('users', 'users.id', '=', 'departments.manager_user_id')
            ->select([
                'departments.id',
                'departments.site_id',
                'departments.name',
                'users.uuid as manager_user_uuid',
                'users.name as manager_name',
            ]);
    }

    private function toView(Department $row): DepartmentView
    {
        $uuid = $row->getAttribute('manager_user_uuid');
        $name = $row->getAttribute('manager_name');

        return new DepartmentView(
            department: $this->toEntity($row),
            managerUserUuid: \is_string($uuid) ? $uuid : null,
            managerName: \is_string($name) ? $name : null,
        );
    }

    private function toEntity(Department $row): DepartmentEntity
    {
        return new DepartmentEntity(
            id: $row->id,
            siteId: $row->site_id,
            name: $row->name,
        );
    }

    private function translate(QueryException $exception, string $name): QueryException|DepartmentNameAlreadyTaken
    {
        return str_contains($exception->getMessage(), 'departments_site_id_name_unique')
            ? DepartmentNameAlreadyTaken::forName($name)
            : $exception;
    }
}
