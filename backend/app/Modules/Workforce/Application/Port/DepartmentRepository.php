<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;

/**
 * Los departamentos de cada centro.
 */
interface DepartmentRepository
{
    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function add(Department $department): Department;

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function save(Department $department): void;

    public function findById(int $id): ?Department;

    /**
     * Departamentos del centro indicado, o todos si no se filtra.
     *
     * @return list<Department>
     */
    public function all(?int $siteId = null): array;
}
