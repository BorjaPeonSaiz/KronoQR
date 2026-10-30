<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Domain\Support\CivilDate;
use App\Modules\Workforce\Application\Command\RehireContractTerms;
use App\Modules\Workforce\Application\Command\RehireEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmploymentContractRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmploymentContractRegistered;
use App\Modules\Workforce\Domain\Exception\OverlappingEmploymentContract;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * El contrato de una readmision (RN-27, RF-GP-02, RF-IN-03, ADR-062 §6).
 *
 * - El ultimo contrato, si seguia abierto, se cierra en el **cese del periodo
 *   anterior** y no en la vispera de la readmision: el hueco entre los dos
 *   periodos no cuenta como dia contratado.
 * - El periodo nuevo recibe un contrato con los terminos pedidos o, si no se
 *   pidio nada, con los del ultimo; con `contract: null`, ninguno.
 *
 * Cada contrato nuevo deja su `EmploymentContractRegistered` y por tanto su
 * asiento `contract.registered`, como el alta de un contrato desde la ficha.
 * Corre dentro de la transaccion de la readmision, con la cadena ya tomada.
 */
final readonly class RehireContracts
{
    public function __construct(
        private EmploymentContractRepository $contracts,
        private WorkforceEventPublisher $events,
    ) {}

    /**
     * @param  string  $previousTermination  cese del periodo anterior, `Y-m-d`
     *
     * @throws OverlappingEmploymentContract si el contrato abierto empieza en el periodo nuevo
     */
    public function apply(RehireEmployeeCommand $command, Employee $rehired, string $previousTermination, DateTimeImmutable $now): void
    {
        $history = $this->contracts->forEmployee($rehired->uuid);
        $last = $history === [] ? null : $history[\count($history) - 1];
        $previousValidTo = $this->closeOpenContract($rehired, $previousTermination);

        $terms = $command->contractGiven ? $command->contract : self::termsOf($last);

        if (! $terms instanceof RehireContractTerms) {
            return;
        }

        $contract = new EmploymentContract(
            employeeUuid: $rehired->uuid,
            weeklyHours: $terms->weeklyHours,
            annualHours: $terms->annualHours,
            scheduleType: $terms->scheduleType,
            validFrom: CivilDate::midnight($rehired->hiredAt->format('Y-m-d'), new DateTimeZone('UTC')),
            validTo: null,
        );

        $this->contracts->add($contract, $command->actorUserId);

        $this->events->publish(new EmploymentContractRegistered(
            employeeUuid: $rehired->uuid,
            siteId: $rehired->siteId,
            weeklyHours: $contract->weeklyHours,
            annualHours: $contract->annualHours,
            scheduleType: $contract->scheduleType->value,
            validFrom: $contract->isoValidFrom(),
            previousValidTo: $previousValidTo ?? $last?->isoValidTo(),
            previousWeeklyHours: $last?->weeklyHours,
            previousAnnualHours: $last?->annualHours,
            occurredAt: $now,
        ));
    }

    /**
     * Cierra el contrato abierto en el cese anterior, o la vispera del alta
     * nueva si empezo despues del cese. Devuelve el `valid_to` que le queda.
     *
     * @throws OverlappingEmploymentContract
     */
    private function closeOpenContract(Employee $rehired, string $previousTermination): ?string
    {
        $open = $this->contracts->openContractFor($rehired->uuid);

        if (! $open instanceof EmploymentContract) {
            return null;
        }

        $hiredOn = $rehired->hiredAt->format('Y-m-d');
        $validFrom = $open->isoValidFrom();

        if ($validFrom >= $hiredOn) {
            throw OverlappingEmploymentContract::forEmployee($rehired->uuid, $hiredOn);
        }

        $end = $validFrom <= $previousTermination
            ? CivilDate::midnight($previousTermination, new DateTimeZone('UTC'))->add(new DateInterval('P1D'))
            : CivilDate::midnight($hiredOn, new DateTimeZone('UTC'));

        $closed = $open->closedBefore($end);
        $this->contracts->close($closed);

        return $closed->isoValidTo();
    }

    private static function termsOf(?EmploymentContract $contract): ?RehireContractTerms
    {
        return $contract instanceof EmploymentContract
            ? new RehireContractTerms($contract->weeklyHours, $contract->annualHours, $contract->scheduleType)
            : null;
    }
}
