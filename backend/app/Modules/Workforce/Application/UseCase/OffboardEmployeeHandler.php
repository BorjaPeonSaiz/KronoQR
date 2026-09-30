<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\Model\Employee;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Baja de empleado (RF-GP-03, RN-14).
 *
 * **Desactivacion logica, nunca borrado** (regla dura 5). Ni esta clase ni el
 * repositorio tienen un `delete`: la ficha cambia de estado y gana su fecha de
 * cese, y todo lo demas —tramos, jornadas, escaneos— sigue exactamente donde
 * estaba. El registro horario se conserva cuatro anos (RL-02) y una inspeccion
 * puede pedir el de alguien que ya no trabaja en el hotel.
 *
 * **Una transaccion con todo lo que la baja arrastra** (N1, AUD-2). El evento
 * se publica dentro: `Identity` revoca la credencial y cierra el portal
 * (RN-14) y `Compliance` escribe el asiento `employee.offboarded`, los dos en
 * listeners sincronos. Si cualquiera falla, la baja no se confirma: una baja
 * sin traza, o una persona de baja con la tarjeta aun activa, es peor que una
 * baja que hay que repetir.
 */
final readonly class OffboardEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws EmployeeAlreadyTerminated cuando ya estaba de baja
     * @throws InvalidEmploymentPeriod cuando el cese es anterior al alta
     */
    public function handle(OffboardEmployeeCommand $command): ?Employee
    {
        return $this->connection->transaction(function () use ($command): ?Employee {
            $employee = $this->employees->findByUuid($command->uuid);

            if ($employee === null) {
                return null;
            }

            $terminated = $employee->offboard(new DateTimeImmutable($command->terminatedAt));

            $this->employees->save($terminated);

            $this->events->publish(new EmployeeOffboarded(
                employeeUuid: $terminated->uuid,
                terminatedOn: $command->terminatedAt,
                reason: $command->reason,
                occurredAt: $this->clock->now(),
            ));

            return $terminated;
        });
    }
}
