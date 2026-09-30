<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\RenameDepartmentCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\DepartmentRenamed;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Database\ConnectionInterface;

/**
 * Renombrado de un departamento.
 *
 * No cambia de centro y no es una omision: los empleados del departamento estan
 * adscritos a ese centro, y moverlo les cambiaria la zona horaria con la que se
 * calcula su jornada (RN-05).
 *
 * **Deja asiento** (AUD-2, regla dura 6): `DepartmentRenamed` con la lista de
 * campos tocados —sin valores— se publica dentro de la transaccion y
 * `Compliance` escribe `department.renamed`. Un `PATCH` con el mismo nombre no
 * cambia nada y no deja asiento: un asiento que dice que algo cambio cuando no
 * cambio nada miente.
 */
final readonly class RenameDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departments,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function handle(RenameDepartmentCommand $command): ?Department
    {
        return $this->connection->transaction(function () use ($command): ?Department {
            $department = $this->departments->findById($command->id);

            if ($department === null) {
                return null;
            }

            $renamed = $department->rename($command->name);

            $this->departments->save($renamed);

            if ($renamed->name !== $department->name) {
                $this->events->publish(new DepartmentRenamed(
                    departmentId: $renamed->id ?? $command->id,
                    changedFields: ['name'],
                    occurredAt: $this->clock->now(),
                ));
            }

            return $renamed;
        });
    }
}
