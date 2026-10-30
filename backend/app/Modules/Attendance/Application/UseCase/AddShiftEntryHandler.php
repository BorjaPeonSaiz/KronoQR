<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Exception\EmployeeCannotBeClocked;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ShiftEntryCorrectionLedger;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\ClockingPolicies;
use App\Modules\Attendance\Application\Support\ContinuationAcrossWorkDays;
use App\Modules\Attendance\Application\Support\ContinuationCeilings;
use App\Modules\Attendance\Application\Support\Corrections;
use App\Modules\Attendance\Application\Support\ManualEntryRecheck;
use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\EntryDoesNotContinueWorkDay;
use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkInFuture;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\Policy\ManualEntryHorizon;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\EntryClosure;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Application\Port\UuidGenerator;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * **Da de alta un tramo que nunca se ficho** (RF-PA-04, accion `created`).
 *
 * El olvido de fichaje de entrada, el dia que no habia tarjeta entregada
 * (`CREDENCIAL_NO_ENTREGADA`, ADR-034) y las jornadas anteriores a la puesta en
 * marcha (`ALTA_RETROACTIVA`). Un tramo escrito a mano vale para la nomina
 * exactamente igual que uno escaneado: pasa por las mismas invariantes del
 * agregado y solo se distingue por su `clock_in_source` —`manual_admin`— y por
 * la fila de `shift_corrections` que lo explica.
 *
 * **La jornada la declara quien llama y aqui no se adivina.** Ver el docblock de
 * `AddShiftEntryCommand`: derivarla de la hora de entrada mandaria la vuelta de
 * una pausa de madrugada al dia siguiente y partiria el turno de noche (RN-05,
 * ADR-006, ADR-024). Lo unico que se resuelve aqui es **la zona** en que esa
 * fecha es civil, que es un dato del centro (`SiteCalendar`).
 *
 * **Se comprueba que el empleado existe y puede recibir el tramo** (RN-14,
 * {@see ManualEntryEligibility}). No es autorizacion —eso es la policy— sino
 * integridad: una persona en alta, o una **dada de baja** si la jornada esta
 * entre su alta y su cese (2.2.0), que es como se completan los dias trabajados
 * que no constaron. Una suspendida, o una jornada de una baja fuera de su
 * periodo de empleo, produciria un registro que nadie sabe defender. La respuesta
 * aqui **si** puede decir por que, al contrario que en el quiosco: quien la
 * recibe es un responsable autenticado, no una pantalla en un pasillo (RS-03 y
 * la regla dura 17 hablan del escaneo).
 *
 * **Nada en el futuro** (RL-01, RL-04). La jornada y las dos marcas se
 * comparan con la hora del servidor mas `ATTENDANCE_FUTURE_TOLERANCE_MINUTES`
 * ({@see ManualEntryHorizon}) antes de tocar el agregado, y el agregado lo
 * vuelve a exigir con el mismo horizonte (RN-24): rellenar la jornada teorica
 * por adelantado o dejar escrita la salida «prevista» es un `422`.
 *
 * **Ni mas alla de la jornada ni del cese** (RN-05, 2.3.0). La cota de
 * continuacion —el descanso minimo entre jornadas del perfil, la misma que acota
 * la vuelta de una pausa en el quiosco— la resuelve {@see ContinuationCeilings}
 * y la aplican dos sitios: {@see ManualEntryEligibility::assertTimesAllowed()},
 * que no deja pasar ninguna marca de una persona de baja mas alla de su dia de
 * cese, y el agregado, que no deja colgar de esta jornada un tramo de otro dia
 * civil que no la continua. Orden: horizonte, jornada, cese y agregado.
 */
final readonly class AddShiftEntryHandler
{
    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayRepository $workDays,
        private ShiftEntryCorrectionLedger $ledger,
        private EmployeeDirectory $employees,
        private SiteCalendar $calendar,
        private OperationalSettingsProvider $settings,
        private CompliancePolicyProvider $compliance,
        private EventPublisher $events,
        private Clock $clock,
        private ManualEntryRecheck $recheck,
        private UuidGenerator $uuids,
    ) {}

    /**
     * @throws EmployeeCannotBeClocked el empleado no existe, no puede fichar o su centro no tiene zona
     * @throws ShiftEntryMarkInFuture la jornada o alguna marca es posterior a la hora del servidor mas el margen
     * @throws WorkDateOutsideEmployment la persona esta de baja y la jornada cae fuera de su periodo de empleo
     * @throws ShiftEntryMarkAfterTermination la persona esta de baja y una marca pasa de la cota de su dia de cese (RN-05)
     * @throws CorrectionWouldChangeWorkDate el tramo abriria la jornada en otro dia civil (RN-05)
     * @throws EntryDoesNotContinueWorkDay el tramo es de otro dia civil y no continua la jornada (RN-05)
     */
    public function handle(AddShiftEntryCommand $command): CorrectedShiftEntry
    {
        return UseCaseInstrumentation::run('attendance.add_shift_entry', fn (): CorrectedShiftEntry => $this->doHandle($command));
    }

    private function doHandle(AddShiftEntryCommand $command): CorrectedShiftEntry
    {
        $performedAt = $this->clock->now();

        $employee = $this->employees->findWithEmploymentPeriods($command->employeeUuid);

        // RN-14 (2.2.0): en alta, o de baja para jornadas de su periodo de
        // empleo, que se comprueban en `add()` cuando ya hay `WorkDate`.
        if (! $employee instanceof EmployeeSnapshot || ! ManualEntryEligibility::of($employee)->admitsEntries()) {
            throw EmployeeCannotBeClocked::withUuid($command->employeeUuid);
        }

        return $this->connection->transaction(
            fn (): CorrectedShiftEntry => $this->add($command, $employee, $performedAt),
        );
    }

    /**
     * @throws EmployeeCannotBeClocked
     * @throws ShiftEntryMarkInFuture
     * @throws WorkDateOutsideEmployment
     * @throws ShiftEntryMarkAfterTermination
     * @throws CorrectionWouldChangeWorkDate
     * @throws EntryDoesNotContinueWorkDay
     */
    private function add(
        AddShiftEntryCommand $command,
        EmployeeSnapshot $employee,
        DateTimeImmutable $performedAt,
    ): CorrectedShiftEntry {
        // RN-23 (ADR-056): la zona se lee DENTRO de la transaccion y con la fila
        // del centro compartida hasta confirmar. Leida fuera, un alta manual que
        // fuera el primer tramo podia atribuirse con la zona vieja justo cuando
        // un cambio de zona la daba por libre.
        $timezone = $this->calendar->timezoneForRecording($employee->siteId);

        if (! $timezone instanceof DateTimeZone) {
            throw EmployeeCannotBeClocked::withUuid($command->employeeUuid);
        }

        $workDate = WorkDate::fromIsoDate($command->workDate, $timezone);
        // RN-05 (2.3.0): un tramo cerrado termina en salida salvo que se declare
        // la pausa; declararla sin salida es un estado imposible (`422`).
        $times = ShiftEntryTimes::of(
            $command->clockedInAt,
            $command->clockedOutAt,
            $command->endsInBreak ? EntryClosure::BREAK_START : null,
        );
        $settings = $this->settings->forSite($employee->siteId);

        // Lo que no ha ocurrido no se anota (RN-24). La jornada primero, porque
        // una jornada futura con horas pasadas daria un error de RN-05 que no
        // dice cual es el problema. La regla la hace cumplir el agregado, que
        // recibe el horizonte; se mira tambien aqui, antes de la ficha, para
        // que el `422` de una persona de baja diga eso y no otra cosa.
        $horizon = ManualEntryHorizon::at($performedAt, $settings->manualEntryFutureToleranceMinutes);
        $horizon->assertWorkDateAllowed($workDate);
        $horizon->assertTimesAllowed($times);

        // RN-14 y RN-05 (2.3.0): a una persona de baja solo se le completan
        // jornadas entre su alta y su cese, y ninguna marca le puede pasar de la
        // cota de continuacion contada desde el final de su dia de cese. La
        // cota es la misma que la vuelta de una pausa en el quiosco. Antes de
        // tocar el agregado: un `422` no escribe nada.
        $continuation = ContinuationCeilings::forSite($this->compliance, $employee->siteId);
        ManualEntryRecheck::assertAllowed($employee, $workDate, $times, $continuation, true);

        // La jornada puede no existir todavia: un alta retroactiva de un dia en
        // el que la persona no ficho nada la crea.
        $workDay = $this->workDays->findWorkDayFor($employee->employeeUuid, $workDate)
            ?? WorkDay::start($employee->employeeUuid, $employee->siteId, $workDate);

        // El hueco que el tramo dice continuar, si empieza otro dia: se mira
        // antes de anadirlo, sobre la jornada como estaba.
        $gap = $workDay->continuationGapFor($times);

        $entry = $workDay->addEntry(
            $this->uuids->next(),
            $times,
            ScanOrigin::MANUAL_ADMIN,
            Correction::by($command->performedByUserId, $performedAt, $command->reason),
            ClockingPolicies::forSettings($settings),
            $continuation,
            $horizon,
        );

        // RN-05 frente a las otras jornadas de la persona, que el agregado no ve.
        ContinuationAcrossWorkDays::assertUninterrupted($this->workDays, $workDay, $gap, $continuation);

        $this->workDays->save($workDay);

        $events = $workDay->releaseEvents();
        $correction = Corrections::in($events);

        $this->ledger->record($correction);
        $this->events->publish(...$events);

        // RN-14 y RN-05: la ficha otra vez, con la cadena tomada.
        $this->recheck->afterAudit($employee->employeeUuid, $workDate, $times, $continuation, true);

        return CorrectedShiftEntry::of($workDay, $entry, $correction);
    }
}
