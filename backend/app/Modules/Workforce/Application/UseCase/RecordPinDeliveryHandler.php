<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\RecordPinDeliveryCommand;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinDeliveryRecord;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeePinDelivered;
use App\Modules\Workforce\Domain\Exception\PinAlreadyDelivered;
use App\Modules\Workforce\Domain\Exception\PinNotIssued;
use Illuminate\Database\ConnectionInterface;

/**
 * Registro de la entrega del PIN (RF-ID-09,
 * `POST /employees/{uuid}/pin/deliver`).
 *
 * **Por que esto es un endpoint y no una casilla.** RF-ID-09 pide registrar la
 * entrega «igual que la credencial» (RF-QR-06): ante una discusion sobre un
 * fichaje por PIN hay que poder decir quien entrego ese PIN y cuando. Sin este
 * acuse, la unica respuesta seria «alguien, en algun momento».
 *
 * **Y por que la entrega es presencial.** El producto no depende del correo del
 * empleado (regla dura 12, ADR-015): no hay envio, ni enlace de recuperacion, ni
 * invitacion. Este caso de uso es la contrapartida de esa decision — el acto que
 * sustituye al correo — y se hace en el mismo momento que se entregan la tarjeta
 * y la hoja de instrucciones.
 *
 * **Un caso de uso, una transaccion**, con el asiento de `audit_log` dentro: una
 * entrega registrada sin traza no sirve para lo unico que se le pide.
 *
 * **No devuelve ningun PIN**, ni el entregado ni ningun otro. El PIN existe en
 * claro en la respuesta que lo emitio y en ninguna mas.
 */
final readonly class RecordPinDeliveryHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private EmployeePinRepository $pins,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @return PinDeliveryRecord|null `null` si el empleado no existe
     *
     * @throws PinNotIssued cuando no hay PIN que entregar
     * @throws PinAlreadyDelivered cuando ya constaba entregado
     */
    public function handle(RecordPinDeliveryCommand $command): ?PinDeliveryRecord
    {
        $employee = $this->employees->findByUuid($command->employeeUuid);

        if ($employee === null) {
            return null;
        }

        $deliveredAt = $this->clock->now();

        return $this->connection->transaction(function () use (
            $command,
            $employee,
            $deliveredAt,
        ): ?PinDeliveryRecord {
            $record = $this->pins->recordDelivery(
                $command->employeeUuid,
                $command->deliveredByUserUuid,
                $deliveredAt,
            );

            if (! $record instanceof PinDeliveryRecord) {
                return null;
            }

            $this->events->publish(new EmployeePinDelivered(
                employeeUuid: $command->employeeUuid,
                siteId: $employee->siteId,
                deliveredByUserUuid: $command->deliveredByUserUuid,
                occurredAt: $deliveredAt,
            ));

            return $record;
        });
    }
}
