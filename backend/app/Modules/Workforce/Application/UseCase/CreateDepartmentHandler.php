<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\CreateDepartmentCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;
use App\Modules\Workforce\Domain\Model\Site;

/**
 * Alta de departamento en el centro de la instalacion (ADR-040).
 *
 * El centro no viene en el comando: lo resuelve este caso de uso. Sin centro no
 * hay alta posible, porque `departments.site_id` es obligatorio.
 */
final readonly class CreateDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departments,
        private SiteRepository $sites,
    ) {}

    /**
     * @throws DepartmentNameAlreadyTaken
     * @throws InstallationSiteMissing
     */
    public function handle(CreateDepartmentCommand $command): Department
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site || $site->id === null) {
            throw InstallationSiteMissing::make();
        }

        return $this->departments->add(
            Department::create($site->id, $command->name)
        );
    }
}
