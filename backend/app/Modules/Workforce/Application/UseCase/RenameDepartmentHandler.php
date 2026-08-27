<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\RenameDepartmentCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;

/**
 * Renombrado de un departamento.
 *
 * No cambia de centro y no es una omision: los empleados del departamento estan
 * adscritos a ese centro, y moverlo les cambiaria la zona horaria con la que se
 * calcula su jornada (RN-05).
 */
final readonly class RenameDepartmentHandler
{
    public function __construct(private DepartmentRepository $departments) {}

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function handle(RenameDepartmentCommand $command): ?Department
    {
        $department = $this->departments->findById($command->id);

        if ($department === null) {
            return null;
        }

        $renamed = $department->rename($command->name);

        $this->departments->save($renamed);

        return $renamed;
    }
}
