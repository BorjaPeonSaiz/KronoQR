<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Domain\Model\Department;

final readonly class DepartmentQueries
{
    public function __construct(private DepartmentRepository $departments) {}

    /**
     * @return list<Department>
     */
    public function all(): array
    {
        return $this->departments->all();
    }

    public function find(int $id): ?Department
    {
        return $this->departments->findById($id);
    }
}
