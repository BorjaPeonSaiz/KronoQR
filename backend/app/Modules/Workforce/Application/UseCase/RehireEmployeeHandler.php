<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Port\UuidGenerator;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Command\RehireEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\ParentRowLocks;
use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeRehired;
use App\Modules\Workforce\Domain\Exception\EmployeeNotTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidRehireDate;
use App\Modules\Workforce\Domain\Exception\MinimisedEmploymentHistory;
use App\Modules\Workforce\Domain\Model\Employee;
use DateTimeImmutable;
use RuntimeException;

/**
 * Readmision de una persona de baja en su misma ficha (RN-27, ADR-062 §6, D16).
 *
 * **Que se escribe**, en una transaccion y con el orden de candados de ADR-046
 * ampliado a la derecha de la ficha: departamento nuevo con `FOR KEY SHARE`
 * antes de la cadena → cadena → `employees` → `employment_periods` y
 * `employment_contracts` → `credentials` → sesiones del portal.
 *
 * 1. La ficha vuelve a `active` con la fecha de alta nueva y sin cese, y se abre
 *    el periodo nuevo ({@see Employee::rehire()} decide si cabe).
 * 2. Departamento e idioma, si cambian. Nombre, documento y codigo no.
 * 3. El contrato ({@see RehireContracts}).
 * 4. `EmployeeRehired`: `Identity` emite la tarjeta nueva pendiente de imprimir,
 *    con el `uuid` que se genera aqui, y `Compliance` escribe `employee.rehired`.
 * 5. Con `reset_pin`, un PIN nuevo, calculado **antes** de la cadena (ADR-046
 *    A-3). Sin el, el PIN se conserva: la baja no lo borra y el portal vuelve a
 *    admitir a la persona porque lo decide `status`.
 *
 * **El documento no interviene**: la readmision no inserta ficha. Dos
 * readmisiones de la misma persona se serializan por el candado de la ficha, y la
 * segunda ve la ficha ya en alta y responde `409`. La purga de retencion toma la
 * cadena antes que la ficha, como esto: o minimiza antes y la readmision responde
 * `409`, o espera y ya no encuentra una baja que minimizar.
 */
final readonly class RehireEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private ParentRowLocks $parentRows,
        private RehireContracts $contracts,
        private IssueEmployeePinHandler $pins,
        private WorkforceEventPublisher $events,
        private EmployeeWriteRetry $retry,
        private SerializedLedgerWrite $serialized,
        private Clock $clock,
        private UuidGenerator $uuids,
    ) {}

    /**
     * `null` si la persona no existe.
     *
     * @throws EmployeeNotTerminated
     * @throws MinimisedEmploymentHistory
     * @throws InvalidRehireDate
     */
    public function handle(RehireEmployeeCommand $command): ?RehiredEmployee
    {
        return UseCaseInstrumentation::run('workforce.rehire_employee', fn (): ?RehiredEmployee => $this->doHandle($command));
    }

    /**
     * El PIN nuevo, calculado fuera de cualquier transaccion (ADR-046 A-3), o
     * `null` si se conserva.
     */
    public function pinMaterialFor(RehireEmployeeCommand $command): ?PinMaterial
    {
        return $command->resetPin ? $this->pins->freshMaterial() : null;
    }

    private function doHandle(RehireEmployeeCommand $command): ?RehiredEmployee
    {
        $material = $this->pinMaterialFor($command);

        return $this->retry->run('employee.rehire', function () use ($command, $material): ?RehiredEmployee {
            if ($command->departmentGiven && $command->departmentId !== null) {
                $this->parentRows->shareDepartments([$command->departmentId]);
            }

            return $this->serialized->withChainLock(function () use ($command, $material): ?RehiredEmployee {
                $employee = $this->employees->findForUpdate($command->uuid);

                return $employee instanceof Employee ? $this->rehireLocked($employee, $command, $material) : null;
            });
        });
    }

    /**
     * La readmision de una ficha **ya bloqueada**, con la cadena tomada y dentro
     * de la transaccion de quien llama. La usan esta clase, el lote y la
     * importacion, para que los tres caminos escriban exactamente lo mismo.
     *
     * @throws EmployeeNotTerminated
     * @throws MinimisedEmploymentHistory
     * @throws InvalidRehireDate
     */
    public function rehireLocked(Employee $employee, RehireEmployeeCommand $command, ?PinMaterial $material): RehiredEmployee
    {
        $now = $this->clock->now();
        $previousTermination = $employee->terminatedAt?->format('Y-m-d');

        $rehired = $employee->rehire(
            new DateTimeImmutable($command->hiredAt),
            $command->departmentId,
            $command->departmentGiven,
            $command->locale,
        );

        $periodUuid = $this->uuids->next();
        $credentialUuid = $this->uuids->next();

        $this->employees->saveRehire($rehired, $periodUuid, $command->actorUserId);
        $this->contracts->apply($command, $rehired, (string) $previousTermination, $now);

        $pin = $material === null ? null : $this->pins->handle(new IssueEmployeePinCommand(
            employeeUuid: $rehired->uuid,
            siteId: $rehired->siteId,
            reset: true,
            material: $material,
        ));

        if ($material !== null && ! $pin instanceof IssuedPin) {
            throw new RuntimeException('La readmision no ha podido emitir el PIN del empleado '.$rehired->uuid.'.');
        }

        $this->events->publish(new EmployeeRehired(
            employeeUuid: $rehired->uuid,
            employmentPeriodUuid: $periodUuid,
            hiredOn: $rehired->hiredAt->format('Y-m-d'),
            departmentId: $rehired->departmentId,
            previousDepartmentId: $employee->departmentId,
            pinReset: $pin instanceof IssuedPin,
            viaImport: $command->viaImport,
            countedByBatch: $command->countedByBatch,
            credentialUuid: $credentialUuid,
            actorUserId: $command->actorUserId,
            occurredAt: $now,
        ));

        return new RehiredEmployee(
            employee: $rehired,
            period: new EmploymentPeriod($rehired->hiredAt->format('Y-m-d'), null, false, $periodUuid),
            credentialUuid: $credentialUuid,
            pin: $pin,
        );
    }
}
