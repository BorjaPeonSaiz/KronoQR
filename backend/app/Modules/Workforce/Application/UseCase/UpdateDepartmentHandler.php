<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Workforce\Application\Command\UpdateDepartmentCommand;
use App\Modules\Workforce\Application\Exception\DepartmentManagerNotEligible;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\DepartmentView;
use App\Modules\Workforce\Application\Port\EligibleManager;
use App\Modules\Workforce\Application\Port\ManagementAccountLookup;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\DepartmentManagerChanged;
use App\Modules\Workforce\Domain\Event\DepartmentRenamed;
use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Database\ConnectionInterface;

/**
 * Cambio de un departamento: su nombre, su responsable, o las dos cosas
 * (RF-GP-01, RF-ID-03, RF-ID-10, ADR-051 §5).
 *
 * No cambia de centro y no es una omision: los empleados del departamento estan
 * adscritos a ese centro, y moverlo les cambiaria la zona horaria con la que se
 * calcula su jornada (RN-05).
 *
 * ## Asientos (regla dura 6)
 *
 * - **Nombre**: `DepartmentRenamed` con la lista de campos tocados —sin
 *   valores— y `Compliance` escribe `department.renamed`.
 * - **Responsable**: `DepartmentManagerChanged` y `Compliance` escribe un
 *   `role_assignment.changed` por cada cuenta afectada, la que sale y la que
 *   entra. Es un cambio de permisos de dos personas.
 *
 * Lo que no cambia nada no deja asiento: un asiento que dice que algo cambio
 * cuando no cambio nada miente.
 *
 * ## Candados (ADR-046 §1.1, ADR-051 §6)
 *
 * Filas padre → cadena → `users`. La fila del departamento se toma
 * `FOR NO KEY UPDATE` **antes** de la cadena, como la toma el `UPDATE` de un
 * renombrado; con la cadena en la mano se lee la cuenta propuesta, y como la
 * baja de una cuenta toma la cadena antes de tocar su fila, la respuesta no
 * puede quedar vieja antes de confirmar. Los asientos se escriben dentro, con
 * la cadena reentrante: si fallan, no se confirma nada.
 *
 * **La cuenta se comprueba antes de escribir nada**: un `422` del responsable
 * no deja el nombre cambiado a medias.
 */
final readonly class UpdateDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departments,
        private ManagementAccountLookup $accounts,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws DepartmentNameAlreadyTaken
     * @throws DepartmentManagerNotEligible si la cuenta no existe, esta de baja o tiene otro rol
     */
    public function handle(UpdateDepartmentCommand $command): ?DepartmentView
    {
        return $this->connection->transaction(function () use ($command): ?DepartmentView {
            $department = $this->departments->findForUpdate($command->id);

            if (! $department instanceof Department) {
                return null;
            }

            return $this->serialized->withChainLock(
                fn (): DepartmentView => $this->apply($command, $department),
            );
        });
    }

    private function apply(UpdateDepartmentCommand $command, Department $current): DepartmentView
    {
        $id = $current->id ?? $command->id;
        $before = $this->departments->findView($id) ?? new DepartmentView($current);

        // Antes de escribir nada: un `422` del responsable no deja el nombre
        // cambiado.
        $manager = $command->managerGiven ? $this->resolveManager($command->managerUserUuid) : null;

        $updated = $command->name === null ? $current : $this->rename($id, $current, $command->name);

        if ($command->managerGiven && $manager?->uuid !== $before->managerUserUuid) {
            $this->departments->assignManager($id, $manager?->userId);

            $this->events->publish(new DepartmentManagerChanged(
                departmentId: $id,
                previousManagerUuid: $before->managerUserUuid,
                newManagerUuid: $manager?->uuid,
                occurredAt: $this->clock->now(),
            ));
        }

        return $this->departments->findView($id) ?? new DepartmentView($updated);
    }

    /**
     * `null` quita el responsable; un uuid tiene que ser una cuenta elegible.
     *
     * @throws DepartmentManagerNotEligible
     */
    private function resolveManager(?string $uuid): ?EligibleManager
    {
        if ($uuid === null) {
            return null;
        }

        return $this->accounts->eligibleManager($uuid) ?? throw DepartmentManagerNotEligible::create();
    }

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    private function rename(int $id, Department $current, string $name): Department
    {
        $renamed = $current->rename($name);

        if ($renamed->name === $current->name) {
            return $current;
        }

        $this->departments->save($renamed);

        $this->events->publish(new DepartmentRenamed(
            departmentId: $id,
            changedFields: ['name'],
            occurredAt: $this->clock->now(),
        ));

        return $renamed;
    }
}
