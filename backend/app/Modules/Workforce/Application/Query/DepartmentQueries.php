<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
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
        return UseCaseInstrumentation::run('workforce.list_departments', fn () => $this->doAll());
    }

    /**
     * @return list<DepartmentView>
     */
    private function doAll(): array
    {
        return $this->departments->allViews();
    }

    public function find(int $id): ?DepartmentView
    {
        return UseCaseInstrumentation::run('workforce.find_department', fn (): ?DepartmentView => $this->doFind($id));
    }

    private function doFind(int $id): ?DepartmentView
    {
        return $this->departments->findView($id);
    }
}
