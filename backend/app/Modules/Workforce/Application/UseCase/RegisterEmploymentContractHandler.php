<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\RegisterEmploymentContractCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\EmploymentContractRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmploymentContractRegistered;
use App\Modules\Workforce\Domain\Exception\OverlappingEmploymentContract;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Registra el contrato de una persona y cierra el anterior (**RF-GP-02**).
 *
 * ## Un caso de uso, una transaccion
 *
 * Cerrar el contrato vigente y abrir el nuevo son **un solo hecho**. Si se
 * escribieran por separado y fallara el segundo, la persona se quedaria con un
 * contrato cerrado y ninguno abierto: el informe de RF-IN-03 la contaria a
 * partir de ese dia como «sin contrato vigente» sin que nada lo delatara. Por
 * eso los dos van en la misma transaccion, y el asiento de `audit_log` tambien
 * —es sincrono y tiene que poder impedir el cambio si falla (regla dura 6,
 * ADR-027)—.
 *
 * ## El solape no se comprueba con un `SELECT` previo
 *
 * Se comprueba lo que se puede explicar bien —que el contrato nuevo no empiece
 * antes que el vigente, que es la errata tipica de un formulario— y lo demas lo
 * decide `employment_contracts_no_overlap` en PostgreSQL. Una comprobacion
 * completa desde PHP seria una condicion de carrera con aspecto de comprobacion:
 * dos altas simultaneas la pasan las dos.
 *
 * ## La vigencia es calendario, no un instante
 *
 * `valid_from` entra como `YYYY-MM-DD` y se convierte a medianoche UTC, igual
 * que `DateRange`: son etiquetas de calendario que solo se comparan entre si.
 * **No hay `now()` por ningun lado** (regla dura 2) — el instante del evento sale
 * del puerto `Clock` y la fecha de vigencia la decide quien registra el contrato.
 */
final readonly class RegisterEmploymentContractHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private EmploymentContractRepository $contracts,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * `null` si la persona no existe: es un `404` y lo traduce el controlador.
     *
     * @throws OverlappingEmploymentContract cuando el contrato nuevo pisa a otro ya registrado
     */
    public function handle(RegisterEmploymentContractCommand $command): ?EmploymentContract
    {
        $employee = $this->employees->findByUuid($command->employeeUuid);

        if ($employee === null) {
            return null;
        }

        $validFrom = $this->asDate($command->validFrom);

        $contract = new EmploymentContract(
            employeeUuid: $command->employeeUuid,
            weeklyHours: $command->weeklyHours,
            annualHours: $command->annualHours,
            scheduleType: $command->scheduleType,
            validFrom: $validFrom,
            // Siempre abierto: lo cierra el que venga despues. Ver el comando.
            validTo: null,
        );

        return $this->connection->transaction(function () use (
            $command,
            $contract,
            $employee,
            $validFrom,
        ): EmploymentContract {
            $previous = $this->contracts->openContractFor($command->employeeUuid);
            $previousValidTo = null;

            if ($previous !== null) {
                if ($previous->validFrom >= $validFrom) {
                    // El nuevo empezaria antes o el mismo dia que el vigente. La
                    // restriccion de exclusion lo rechazaria igual, pero aqui hay
                    // un mensaje que le dice a quien rellena el formulario que la
                    // fecha va despues del contrato que ya tiene.
                    throw OverlappingEmploymentContract::forEmployee(
                        $command->employeeUuid,
                        $contract->isoValidFrom(),
                    );
                }

                $closed = $previous->closedBefore($validFrom);
                $this->contracts->close($closed);
                $previousValidTo = $closed->isoValidTo();
            }

            $id = $this->contracts->add($contract, $command->registeredByUserId);

            // Dentro de la transaccion: el asiento de `audit_log` es sincrono y
            // si falla, el contrato no se registra (regla dura 6). Un cambio de
            // horas contratadas sin traza es un cambio del calculo que nadie
            // puede explicar despues.
            $this->events->publish(new EmploymentContractRegistered(
                employeeUuid: $command->employeeUuid,
                siteId: $employee->siteId,
                weeklyHours: $contract->weeklyHours,
                annualHours: $contract->annualHours,
                scheduleType: $contract->scheduleType->value,
                validFrom: $contract->isoValidFrom(),
                previousValidTo: $previousValidTo,
                occurredAt: $this->clock->now(),
            ));

            return new EmploymentContract(
                employeeUuid: $contract->employeeUuid,
                weeklyHours: $contract->weeklyHours,
                annualHours: $contract->annualHours,
                scheduleType: $contract->scheduleType,
                validFrom: $contract->validFrom,
                validTo: $contract->validTo,
                id: $id,
            );
        });
    }

    private function asDate(string $isoDate): DateTimeImmutable
    {
        // El `FormRequest` ya ha comprobado el formato; esto solo lo fija a
        // medianoche UTC para que la comparacion sea entre fechas.
        return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
    }
}
