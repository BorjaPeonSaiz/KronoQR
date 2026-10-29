<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\CancelScheduledOffboardingCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeOffboardingCancelled;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\OffboardingNotScheduled;
use App\Modules\Workforce\Domain\Exception\ScheduledOffboardingDue;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use DateTimeImmutable;

/**
 * Anula una baja programada (RN-14, RF-GP-03, ADR-062 §3).
 *
 * La ficha pierde su fecha de cese y sigue como estaba: tarjetas, PIN y portal
 * no han cambiado nunca. **Solo mientras el cese no haya pasado** en la zona del
 * centro; la regla la decide {@see Employee::cancelScheduledOffboarding()} con
 * «hoy» resuelto aqui por el puerto `Clock`, como la baja.
 *
 * Mismo orden de candados que la baja y que la tarea que la aplica (ADR-046):
 * cadena → ficha. La que llega segunda relee: si la tarea confirmo antes, la
 * ficha ya esta `terminated` y la anulacion responde `409`; si confirma esta, la
 * tarea encuentra la baja anulada y no hace nada.
 */
final readonly class CancelScheduledOffboardingHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @throws EmployeeAlreadyTerminated la baja ya es efectiva
     * @throws OffboardingNotScheduled no hay baja programada
     * @throws ScheduledOffboardingDue el cese ya paso
     * @throws InstallationSiteMissing sin centro no hay «hoy»
     */
    public function handle(CancelScheduledOffboardingCommand $command): ?Employee
    {
        return UseCaseInstrumentation::run('workforce.cancel_scheduled_offboarding', fn (): ?Employee => $this->doHandle($command));
    }

    private function doHandle(CancelScheduledOffboardingCommand $command): ?Employee
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site) {
            throw InstallationSiteMissing::make();
        }

        $receivedAt = $this->clock->now();
        $today = $receivedAt->setTimezone($site->timezone->toDateTimeZone());

        return $this->serialized->withChainLock(function () use ($command, $today, $receivedAt): ?Employee {
            $employee = $this->employees->findForUpdate($command->uuid);

            if ($employee === null) {
                return null;
            }

            $cancelled = $employee->cancelScheduledOffboarding($today);
            $terminatedAt = $employee->terminatedAt;

            // El dominio ya lo garantiza: sin fecha de cese no hay nada que anular.
            if (! $terminatedAt instanceof DateTimeImmutable) {
                throw OffboardingNotScheduled::withUuid($employee->uuid);
            }

            $this->employees->saveTermination($cancelled);
            $this->events->publish(new EmployeeOffboardingCancelled(
                employeeUuid: $cancelled->uuid,
                terminatedOn: $terminatedAt->format('Y-m-d'),
                occurredAt: $receivedAt,
            ));

            return $cancelled;
        });
    }
}
