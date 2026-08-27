<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\CreateDepartmentCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;

/**
 * Alta de departamento dentro de un centro.
 */
final readonly class CreateDepartmentHandler
{
    public function __construct(private DepartmentRepository $departments) {}

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function handle(CreateDepartmentCommand $command): Department
    {
        return $this->departments->add(
            Department::create($command->siteId, $command->name)
        );
    }
}
