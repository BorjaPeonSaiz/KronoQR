<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Domain\Model\Department;

/**
 * Lecturas de departamentos. Es tambien donde se aplicara el ambito de RF-ID-03
 * cuando un responsable solo deba ver el suyo (tarea 2.1).
 */
final readonly class DepartmentQueries
{
    public function __construct(private DepartmentRepository $departments) {}

    /**
     * @return list<Department>
     */
    public function all(?int $siteId = null): array
    {
        return $this->departments->all($siteId);
    }

    public function find(int $id): ?Department
    {
        return $this->departments->findById($id);
    }
}
