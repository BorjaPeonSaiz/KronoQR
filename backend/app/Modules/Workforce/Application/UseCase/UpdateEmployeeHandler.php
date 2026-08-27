<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use App\Modules\Workforce\Domain\Exception\DepartmentNotInSite;
use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Modificacion de la ficha de un empleado (RF-GP-01).
 *
 * **La baja no pasa por aqui.** `terminated` no es un valor admitido: la baja
 * lleva fecha de cese y consecuencias (RN-14, revocacion de credencial) y tiene
 * su propio caso de uso. Un `PATCH` que pudiera dar de baja acabaria dando bajas
 * sin fecha.
 *
 * Publica que campos cambiaron, **no sus valores**: la lista basta para que la
 * auditoria diga que se toco, y los valores anterior y nuevo son de `audit_log`,
 * que es donde tienen amparo legal y control de acceso (regla dura 21).
 */
final readonly class UpdateEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private DepartmentRepository $departments,
        private WorkforceEventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @throws DepartmentNotInSite
     */
    public function handle(UpdateEmployeeCommand $command): ?Employee
    {
        $current = $this->employees->findByUuid($command->uuid);

        if ($current === null) {
            return null;
        }

        $siteId = $command->siteId ?? $current->siteId;
        $departmentId = $command->departmentGiven ? $command->departmentId : $current->departmentId;

        $this->assertDepartmentBelongsToSite($departmentId, $siteId);

        $updated = $current->updateProfile(
            firstName: $command->firstName,
            lastName: $command->lastName,
            email: $command->email,
            emailGiven: $command->emailGiven,
            siteId: $command->siteId,
            departmentId: $command->departmentId,
            departmentGiven: $command->departmentGiven,
            locale: $command->locale,
        );

        $updated = $this->applyStatus($updated, $command->status);

        $this->employees->save($updated);

        $this->events->publish(new EmployeeProfileUpdated(
            employeeUuid: $updated->uuid,
            changedFields: $this->changedFields($current, $updated),
            occurredAt: $this->clock->now(),
        ));

        return $updated;
    }

    private function applyStatus(Employee $employee, ?string $status): Employee
    {
        return match ($status) {
            EmploymentStatus::ACTIVE->value => $employee->reinstate(),
            EmploymentStatus::SUSPENDED->value => $employee->suspend(),
            default => $employee,
        };
    }

    /**
     * @return list<string>
     */
    private function changedFields(Employee $before, Employee $after): array
    {
        $changed = [];

        $comparisons = [
            'first_name' => [$before->firstName, $after->firstName],
            'last_name' => [$before->lastName, $after->lastName],
            'email' => [$before->email, $after->email],
            'site_id' => [$before->siteId, $after->siteId],
            'department_id' => [$before->departmentId, $after->departmentId],
            'status' => [$before->status->value, $after->status->value],
            'locale' => [$before->locale, $after->locale],
        ];

        foreach ($comparisons as $field => [$old, $new]) {
            if ($old !== $new) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @throws DepartmentNotInSite
     */
    private function assertDepartmentBelongsToSite(?int $departmentId, int $siteId): void
    {
        if ($departmentId === null) {
            return;
        }

        $department = $this->departments->findById($departmentId);

        if ($department === null || $department->siteId !== $siteId) {
            throw DepartmentNotInSite::make($departmentId, $siteId);
        }
    }
}
