<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\CaughtFailureReporter;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FinalWorkDayActivity;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\OffboardingMetrics;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * **La tarea que hace efectivas las bajas programadas** (RN-14, RF-GP-03,
 * ADR-062 §4). La lanza `workforce:apply-scheduled-offboardings` cada hora.
 *
 * ## Que aplica
 *
 * Cada pasada resuelve «hoy» **una sola vez**, con el puerto `Clock` y la zona
 * del centro (reglas duras 2 y 3), y toma a quien tiene una baja programada con
 * la fecha de cese **anterior** a hoy. Compara fechas civiles, nunca instantes:
 * un dia de 23 o de 25 horas tiene igualmente 23 o 25 pasadas, y la primera del
 * dia siguiente al cese es la que la aplica. Sin centro no se pudo programar
 * ninguna baja (ADR-040), y no hay nada que hacer.
 *
 * ## Cuando espera
 *
 * Mientras **la jornada del cese pueda seguir creciendo** —un tramo abierto de
 * una jornada hasta el cese, o una pausa con vuelta posible—, la baja se aplaza
 * para que el turno de noche y la pausa de madrugada terminen fichados. **Tope**:
 * desde el segundo dia civil despues del cese se aplica igual, y el tramo que
 * siga abierto lo detecta RF-PR-01. La pregunta la contesta el registro horario
 * por {@see FinalWorkDayActivity}, porque la cota es suya.
 *
 * ## Como aplica
 *
 * **Una transaccion por persona**: un fallo no detiene a las demas, se cuenta
 * como `failed`, va al log con `employee_uuid` y nunca con nombre (regla dura
 * 21), y al historico de errores. Dentro, el orden de ADR-046: cadena → ficha
 * bloqueada → se comprueba otra vez que la baja sigue vencida y sin anular →
 * {@see Employee::applyScheduledOffboarding()}
 * → `saveTermination()` → {@see EmployeeOffboarded} con `scheduled: true`. Por
 * ese evento `Identity` revoca las tarjetas y cierra el portal en la misma
 * transaccion, y `Compliance` escribe `employee.offboarded` con actor `system`.
 *
 * **Idempotente**: la segunda pasada no encuentra a nadie, y el predicado de
 * `saveTermination()` impide escribir dos veces. **Llegar tarde no cambia la
 * fecha**: la baja lleva su fecha de cese, y el asiento `due_on` y los dias de
 * retraso.
 */
final readonly class ApplyDueOffboardings
{
    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private FinalWorkDayActivity $activity,
        private OffboardingMetrics $metrics,
        private LoggerInterface $logger,
        private CaughtFailureReporter $caughtFailures,
    ) {}

    public function handle(): DueOffboardingsReport
    {
        return UseCaseInstrumentation::run('workforce.apply_scheduled_offboardings', fn (): DueOffboardingsReport => $this->doHandle());
    }

    private function doHandle(): DueOffboardingsReport
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site) {
            return DueOffboardingsReport::withoutSite();
        }

        $now = $this->clock->now();
        $today = $now->setTimezone($site->timezone->toDateTimeZone());
        $todayDate = $today->format('Y-m-d');

        $tally = [
            ScheduledOffboardingOutcome::Applied->value => 0,
            ScheduledOffboardingOutcome::Deferred->value => 0,
            ScheduledOffboardingOutcome::Failed->value => 0,
        ];

        foreach ($this->employees->dueScheduledOffboardings($todayDate) as $uuid) {
            $outcome = $this->applyTo($uuid, $now, $today);

            if ($outcome === ScheduledOffboardingOutcome::Skipped) {
                continue;
            }

            $tally[$outcome->value]++;
            $this->metrics->offboardingProcessed($outcome->value);
        }

        // Al terminar la pasada: lo que siga pendiente con el cese de hace mas
        // de un dia ya paso el tope y no deberia existir.
        $overdue = $this->employees->countScheduledOffboardingsDueBefore(self::shift($todayDate, -1));
        $this->metrics->overdueScheduledOffboardings($overdue);

        return new DueOffboardingsReport(
            ranOverASite: true,
            today: $todayDate,
            applied: $tally[ScheduledOffboardingOutcome::Applied->value],
            deferred: $tally[ScheduledOffboardingOutcome::Deferred->value],
            failed: $tally[ScheduledOffboardingOutcome::Failed->value],
            overdue: $overdue,
        );
    }

    private function applyTo(string $uuid, DateTimeImmutable $now, DateTimeImmutable $today): ScheduledOffboardingOutcome
    {
        try {
            $outcome = $this->serialized->withChainLock(fn (): ScheduledOffboardingOutcome => $this->applyLocked($uuid, $now, $today));
        } catch (Throwable $failure) {
            $this->logger->error('workforce.scheduled_offboarding_failed', [
                'employee_uuid' => $uuid,
                'exception' => $failure::class,
            ]);
            $this->caughtFailures->report($failure);

            return ScheduledOffboardingOutcome::Failed;
        }

        if ($outcome === ScheduledOffboardingOutcome::Deferred) {
            $this->logger->info('workforce.scheduled_offboarding_deferred', ['employee_uuid' => $uuid]);
        }

        return $outcome;
    }

    /**
     * Dentro de la transaccion, con la cadena tomada (ADR-046 §1).
     */
    private function applyLocked(string $uuid, DateTimeImmutable $now, DateTimeImmutable $today): ScheduledOffboardingOutcome
    {
        $employee = $this->employees->findForUpdate($uuid);

        // Anulada, reprogramada o ya aplicada mientras esta pasada llegaba aqui.
        if ($employee === null || ! $employee->isOffboardingDue($today) || ! $employee->terminatedAt instanceof DateTimeImmutable) {
            return ScheduledOffboardingOutcome::Skipped;
        }

        $terminatedOn = $employee->terminatedAt->format('Y-m-d');
        $todayDate = $today->format('Y-m-d');
        $dueOn = self::shift($terminatedOn, 1);

        // Hasta el tope —el segundo dia civil despues del cese— se espera a que
        // la jornada del cese deje de poder crecer.
        if ($todayDate < self::shift($terminatedOn, 2)
            && $this->activity->mayStillGrow($uuid, $employee->siteId, $terminatedOn, $now)) {
            return ScheduledOffboardingOutcome::Deferred;
        }

        $terminated = $employee->applyScheduledOffboarding($today);
        $this->employees->saveTermination($terminated);

        $this->events->publish(new EmployeeOffboarded(
            employeeUuid: $terminated->uuid,
            terminatedOn: $terminatedOn,
            reason: null,
            occurredAt: $now,
            scheduled: true,
            dueOn: $dueOn,
            daysLate: self::daysBetween($dueOn, $todayDate),
        ));

        return ScheduledOffboardingOutcome::Applied;
    }

    /**
     * Una fecha civil `Y-m-d` desplazada `$days` dias de calendario. Se calcula
     * en UTC a medianoche: entre dos fechas civiles no hay cambio de hora.
     */
    private static function shift(string $date, int $days): string
    {
        return self::civil($date)->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        return max(0, (int) self::civil($from)->diff(self::civil($to))->format('%r%a'));
    }

    private static function civil(string $date): DateTimeImmutable
    {
        $civil = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if (! $civil instanceof DateTimeImmutable) {
            throw new \InvalidArgumentException('Fecha civil no valida: '.$date);
        }

        return $civil;
    }
}
