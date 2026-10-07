<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\DepartmentView;

final readonly class DepartmentQueries
{
    public function __construct(private DepartmentRepository $departments) {}

    /**
     * Con su responsable, en una sola consulta (sin N+1).
     *
     * @return list<DepartmentView>
     */
    public function all(): array
    {
        return $this->departments->allViews();
    }

    public function find(int $id): ?DepartmentView
    {
        return $this->departments->findView($id);
    }
}
