<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Exception\EmployeeCannotBeClocked;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\ClockingPolicies;
use App\Modules\Attendance\Application\Support\Corrections;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

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
 * **Se comprueba que el empleado existe y puede fichar** (RN-14). No es
 * autorizacion —eso es la policy— sino integridad: dar de alta horas a una
 * persona dada de baja produce un registro que nadie sabe defender. La respuesta
 * aqui **si** puede decir por que, al contrario que en el quiosco: quien la
 * recibe es un responsable autenticado, no una pantalla en un pasillo (RS-03 y
 * la regla dura 17 hablan del escaneo).
 */
final readonly class AddShiftEntryHandler
{
    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayRepository $workDays,
        private ShiftCorrectionLedger $ledger,
        private EmployeeDirectory $employees,
        private SiteCalendar $calendar,
        private OperationalSettingsProvider $settings,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @throws EmployeeCannotBeClocked el empleado no existe, no puede fichar o su centro no tiene zona
     */
    public function handle(AddShiftEntryCommand $command): CorrectedShift
    {
        $performedAt = $this->clock->now();

        $employee = $this->employees->find($command->employeeUuid);

        if (! $employee instanceof EmployeeSnapshot || ! $employee->canClock()) {
            throw EmployeeCannotBeClocked::withUuid($command->employeeUuid);
        }

        $timezone = $this->calendar->timezoneOf($employee->siteId);

        if (! $timezone instanceof DateTimeZone) {
            throw EmployeeCannotBeClocked::withUuid($command->employeeUuid);
        }

        return $this->connection->transaction(
            fn (): CorrectedShift => $this->add($command, $employee, $timezone, $performedAt),
        );
    }

    private function add(
        AddShiftEntryCommand $command,
        EmployeeSnapshot $employee,
        DateTimeZone $timezone,
        DateTimeImmutable $performedAt,
    ): CorrectedShift {
        $workDate = WorkDate::fromIsoDate($command->workDate, $timezone);

        // La jornada puede no existir todavia: un alta retroactiva de un dia en
        // el que la persona no ficho nada la crea.
        $workDay = $this->workDays->findWorkDayFor($employee->employeeUuid, $workDate)
            ?? WorkDay::start($employee->employeeUuid, $employee->siteId, $workDate);

        $entry = $workDay->addEntry(
            Str::uuid7()->toString(),
            ShiftTimes::of($command->clockedInAt, $command->clockedOutAt),
            ScanOrigin::MANUAL_ADMIN,
            Correction::by($command->performedByUserId, $performedAt, $command->reason),
            ClockingPolicies::forSettings($this->settings->forSite($employee->siteId)),
        );

        $this->workDays->save($workDay);

        $events = $workDay->releaseEvents();
        $correction = Corrections::in($events);

        $this->ledger->record($correction);
        $this->events->publish(...$events);

        return CorrectedShift::of($workDay, $entry, $correction);
    }
}
