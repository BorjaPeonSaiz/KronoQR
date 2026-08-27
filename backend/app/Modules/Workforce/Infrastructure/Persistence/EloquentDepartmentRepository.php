<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\DepartmentRepository;
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

    public function all(?int $siteId = null): array
    {
        $rows = Department::query()
            ->when($siteId !== null, static fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->orderBy('site_id')
            ->orderBy('name')
            ->get();

        return array_values(array_map($this->toEntity(...), $rows->all()));
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
