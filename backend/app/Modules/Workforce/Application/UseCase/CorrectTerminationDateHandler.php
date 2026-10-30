<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Workforce\Application\Command\CorrectTerminationDateCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\TerminationDateCorrected;
use App\Modules\Workforce\Domain\Exception\InvalidTerminationCorrection;
use App\Modules\Workforce\Domain\Exception\EmployeeRecordMinimised;
use App\Modules\Workforce\Domain\Exception\OffboardingNotEffective;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use DateTimeImmutable;

/**
 * Correccion auditada de la fecha de cese de un periodo con baja efectiva
 * (RN-27, RN-13, RL-04, ADR-062 §7).
 *
 * Es el camino cuando RRHH puso un cese anterior al ultimo dia trabajado: los
 * dias que gana el periodo admiten tramos a mano (RF-PA-04), porque la regla de
 * las jornadas lee la fecha de la ficha y del periodo. Los fichajes que se
 * rechazaron esos dias **no se reprocesan**, y las tarjetas siguen revocadas.
 *
 * **Nada se sobrescribe sin rastro**: la fila de `employment_period_corrections`
 * guarda la fecha anterior, la nueva, el motivo, quien y cuando; despues cambia
 * el periodo y, si es el periodo en curso, la ficha. El asiento
 * `employee.termination_date_corrected` lleva las fechas y no el motivo.
 *
 * «Hoy» es la fecha civil **del centro** en el instante en que llega la
 * peticion, como en la baja: el dominio compara fechas y no conoce el reloj.
 * Bajo la cadena y con la ficha bloqueada (ADR-046), como la baja y la
 * readmision: una readmision simultanea espera y ve el cese corregido.
 */
final readonly class CorrectTerminationDateHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * `null` si la persona o el periodo no existen.
     *
     * @throws OffboardingNotEffective
     * @throws InvalidTerminationCorrection
     * @throws EmployeeRecordMinimised
     * @throws InstallationSiteMissing
     */
    public function handle(CorrectTerminationDateCommand $command): ?CorrectedTerminationDate
    {
        return UseCaseInstrumentation::run('workforce.correct_termination_date', fn (): ?CorrectedTerminationDate => $this->doHandle($command));
    }

    private function doHandle(CorrectTerminationDateCommand $command): ?CorrectedTerminationDate
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site) {
            throw InstallationSiteMissing::make();
        }

        $now = $this->clock->now();
        $today = $now->setTimezone($site->timezone->toDateTimeZone())->format('Y-m-d');

        return $this->serialized->withChainLock(function () use ($command, $now, $today): ?CorrectedTerminationDate {
            $employee = $this->employees->findForUpdate($command->employeeUuid);
            $history = $this->employees->employmentHistory($command->employeeUuid);
            $period = $history->find($command->periodUuid);

            if (! $employee instanceof Employee || ! $period instanceof EmploymentPeriod) {
                return null;
            }

            $employee->assertAcceptsPersonnelRecords();

            $correction = TerminationDateCorrection::propose($history, $period, $command->terminatedAt, $today, $command->reason);

            $corrected = $correction->ofCurrentPeriod
                ? $employee->withCorrectedTermination(new DateTimeImmutable($correction->terminatedOn))
                : $employee;

            $id = $this->employees->saveTerminationCorrection($corrected, $correction, $command->correctedByUserId, $now);

            $this->events->publish(new TerminationDateCorrected(
                employeeUuid: $employee->uuid,
                employmentPeriodUuid: $command->periodUuid,
                previousTerminatedOn: $correction->previousTerminatedOn,
                terminatedOn: $correction->terminatedOn,
                correctionId: $id,
                occurredAt: $now,
            ));

            return new CorrectedTerminationDate($id, $command->periodUuid, $correction->previousTerminatedOn, $correction->terminatedOn, $now);
        });
    }
}
