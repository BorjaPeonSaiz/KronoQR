<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Event\EmployeeOffboardingScheduled;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\ImmediateOffboardingNotDue;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use DateTimeImmutable;

/**
 * Baja de empleado, **en el acto o programada** (RF-GP-03, RN-14).
 *
 * **Desactivacion logica, nunca borrado** (regla dura 5). Ni esta clase ni el
 * repositorio tienen un `delete`: la ficha gana su fecha de cese y, si la baja es
 * efectiva, cambia de estado; todo lo demas —tramos, jornadas, escaneos— sigue
 * exactamente donde estaba (RL-02).
 *
 * **Que baja es, lo decide el dominio** ({@see Employee::offboard()}) con la
 * fecha de cese, «hoy» y «Aplicar ya». «Hoy» es la fecha civil **del centro**
 * (`sites.timezone`, ADR-040) en el instante en que el servidor recibe la baja,
 * y se resuelve aqui con el puerto `Clock`: nunca la fecha UTC ni
 * `APP_TIMEZONE`. Sin centro no hay «hoy» que resolver, y la baja falla con
 * {@see InstallationSiteMissing} (`409`) en lugar de caer a UTC.
 *
 * - **Efectiva**: publica {@see EmployeeOffboarded}. `Identity` revoca las
 *   tarjetas y cierra el portal y `Compliance` escribe `employee.offboarded`, los
 *   dos en listeners sincronos y en el orden de ADR-046 (cadena → ficha →
 *   tarjetas).
 * - **Programada**: publica {@see EmployeeOffboardingScheduled}, que solo deja
 *   asiento. La persona ficha hasta su ultimo dia, y la aplica
 *   {@see ApplyDueOffboardings}. Reprogramar a la misma fecha no cambia nada y
 *   no deja asiento: un asiento que dice que se toco la ficha cuando no se toco
 *   miente.
 *
 * **Bajo el candado de la cadena y con la fila bloqueada** (ADR-046). La lectura
 * es `findForUpdate()` dentro de `withChainLock()`: una modificacion, una
 * importacion, una anulacion o la tarea simultaneas esperan a que esta baja
 * confirme y despues la ven. La escritura es `saveTermination()`, que solo toca
 * `status` y `terminated_at` y lleva el predicado `status <> 'terminated'`.
 *
 * **Una transaccion con todo lo que la baja arrastra**: si un listener falla, la
 * baja no se confirma. Una baja sin traza, o una persona de baja con la tarjeta
 * aun activa, es peor que una baja que hay que repetir.
 */
final readonly class OffboardEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @throws EmployeeAlreadyTerminated cuando la baja ya era efectiva
     * @throws InvalidEmploymentPeriod cuando el cese es anterior al alta
     * @throws ImmediateOffboardingNotDue «Aplicar ya» con un cese posterior a hoy
     * @throws InstallationSiteMissing antes de la puesta en marcha: sin centro no hay «hoy»
     */
    public function handle(OffboardEmployeeCommand $command): ?Employee
    {
        return UseCaseInstrumentation::run('workforce.offboard_employee', fn (): ?Employee => $this->doHandle($command));
    }

    private function doHandle(OffboardEmployeeCommand $command): ?Employee
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site) {
            throw InstallationSiteMissing::make();
        }

        $receivedAt = $this->clock->now();

        // La fecha civil del centro en el instante en que llega la baja. Se
        // resuelve una vez, fuera de la cadena: esperar al candado no puede
        // cambiar que dia era cuando se pidio.
        $today = $receivedAt->setTimezone($site->timezone->toDateTimeZone());

        return $this->serialized->withChainLock(function () use ($command, $today, $receivedAt): ?Employee {
            $employee = $this->employees->findForUpdate($command->uuid);

            if ($employee === null) {
                return null;
            }

            $offboarded = $employee->offboard(new DateTimeImmutable($command->terminatedAt), $today, $command->applyNow);

            if ($offboarded->status === EmploymentStatus::TERMINATED) {
                $this->employees->saveTermination($offboarded);
                $this->events->publish(new EmployeeOffboarded(
                    employeeUuid: $offboarded->uuid,
                    terminatedOn: $command->terminatedAt,
                    reason: $command->reason,
                    occurredAt: $receivedAt,
                    // Solo dice algo con el cese de hoy o el de un alta futura: con uno
                    // pasado la baja era en el acto con o sin el indicador.
                    applyNow: $command->applyNow && $command->terminatedAt >= $today->format('Y-m-d'),
                ));

                return $offboarded;
            }

            $previous = $employee->terminatedAt?->format('Y-m-d');

            if ($previous === $command->terminatedAt) {
                return $employee;
            }

            $this->employees->saveTermination($offboarded);
            $this->events->publish(new EmployeeOffboardingScheduled(
                employeeUuid: $offboarded->uuid,
                terminatedOn: $command->terminatedAt,
                previousTerminatedOn: $previous,
                reason: $command->reason,
                occurredAt: $receivedAt,
            ));

            return $offboarded;
        });
    }
}
