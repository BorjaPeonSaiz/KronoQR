<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\CreateDepartmentCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\DepartmentCreated;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;
use App\Modules\Workforce\Domain\Model\Site;
use Illuminate\Database\ConnectionInterface;

/**
 * Alta de departamento en el centro de la instalacion (ADR-040).
 *
 * El centro no viene en el comando: lo resuelve este caso de uso. Sin centro no
 * hay alta posible, porque `departments.site_id` es obligatorio.
 *
 * **Deja asiento** (AUD-2, regla dura 6): un departamento es un ambito de
 * autoridad —decide que responsable ve y corrige la jornada de quien
 * (RF-ID-03)—. `DepartmentCreated` se publica dentro de la transaccion y el
 * listener sincrono de `Compliance` escribe `department.created`: si el asiento
 * falla, el departamento no se crea (ADR-027).
 */
final readonly class CreateDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departments,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
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

        $siteId = $site->id;

        return $this->connection->transaction(function () use ($command, $siteId): Department {
            $department = $this->departments->add(Department::create($siteId, $command->name));

            $this->events->publish(new DepartmentCreated(
                departmentId: $department->id ?? 0,
                siteId: $siteId,
                occurredAt: $this->clock->now(),
            ));

            return $department;
        });
    }
}
