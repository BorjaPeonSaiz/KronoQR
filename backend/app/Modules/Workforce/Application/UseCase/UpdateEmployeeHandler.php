<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Database\ConnectionInterface;

/**
 * Modificacion de la ficha de un empleado (RF-GP-01).
 *
 * **La baja no pasa por aqui.** `terminated` no es un valor admitido: la baja
 * lleva fecha de cese y consecuencias (RN-14, revocacion de credencial) y tiene
 * su propio caso de uso. Un `PATCH` que pudiera dar de baja acabaria dando bajas
 * sin fecha.
 *
 * Publica que campos cambiaron, **no sus valores**: la lista basta para que la
 * auditoria diga que se toco (`employee.updated`, AUD-2) sin que el nombre, el
 * correo o la adscripcion de una persona acaben copiados en ningun sitio mas
 * (regla dura 21).
 *
 * **En una transaccion con el evento dentro** (AUD-2): el listener sincrono de
 * `Compliance` escribe el asiento, y si falla la modificacion no se confirma
 * (ADR-027). Dentro de una importacion la transaccion es anidada: un punto de
 * guardado dentro de la del lote.
 */
final readonly class UpdateEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    public function handle(UpdateEmployeeCommand $command): ?Employee
    {
        return $this->connection->transaction(fn (): ?Employee => $this->update($command));
    }

    private function update(UpdateEmployeeCommand $command): ?Employee
    {
        $current = $this->employees->findByUuid($command->uuid);

        if ($current === null) {
            return null;
        }

        $updated = $current->updateProfile(
            firstName: $command->firstName,
            lastName: $command->lastName,
            email: $command->email,
            emailGiven: $command->emailGiven,
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

        // EL PUNTO DE EXTENSION DEL ASIENTO `employee.updated` (AUD-2). Un campo
        // editable nuevo de la ficha —el teletrabajo del bloque 9, por ejemplo—
        // entra aqui con su nombre de columna y queda trazado sin tocar el
        // evento ni el listener de `Compliance`: el asiento lleva esta lista.
        $comparisons = [
            'first_name' => [$before->firstName, $after->firstName],
            'last_name' => [$before->lastName, $after->lastName],
            'email' => [$before->email, $after->email],
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
}
