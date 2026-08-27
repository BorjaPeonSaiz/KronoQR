<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Application\Exception\ShiftEntryNotFound;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\ClockingPolicies;
use App\Modules\Attendance\Application\Support\Corrections;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * **Rectifica el registro horario de una persona conservando lo que decia
 * antes** (RF-PA-04, RN-13, RL-04, regla dura 5).
 *
 * Cubre las dos acciones que RF-PA-04 distingue y que desde fuera son la misma
 * peticion: cambiar la hora de un tramo cerrado y cerrar un tramo que quedo
 * abierto. Cual de las dos fue lo decide el dominio mirando el estado anterior,
 * no quien llama.
 *
 * Orquesta; no decide. Quien conserva la version anterior, encadena la nueva y
 * comprueba RN-01, RN-02, RN-03 y RN-05 es el agregado `WorkDay`; quien decide
 * si la version corregida pide revision humana es `ClockingPolicy` (RN-07,
 * RN-08). Aqui no hay ni un `if` con una regla de negocio dentro.
 *
 * ## Los seis pasos
 *
 * ```
 * 1. Abrir transaccion
 * 2. Cargar la jornada del tramo por su repositorio
 * 3. Componer las marcas nuevas sobre las que ya hay e invocar al agregado
 * 4. Persistir, con el RECALCULO de daily_totals dentro (RN-06, regla dura 7)
 * 5. Escribir shift_corrections y publicar el evento, aun dentro
 * 6. Devolver un resultado tipado
 * ```
 *
 * **El recalculo (4) lo hace el repositorio, en esta transaccion.** Lo promete
 * el contrato de `WorkDayRepository::save()`. Aqui importa mas que en el
 * fichaje: una correccion es el unico camino por el que el total de un dia puede
 * **bajar**, y un acumulador que solo supiera sumar dejaria en la nomina los
 * minutos de una version que ya no existe (ADR-007, ADR-026).
 *
 * **El asiento de auditoria (5) tambien va dentro.** `Attendance` no puede
 * importar `Compliance` (doc 02 §1.6), asi que la entrada de `audit_log` la
 * escribe un listener suyo al recibir `ShiftCorrected`, igual que con
 * `EmployeeClockedIn`. Para que se sostenga la garantia de la regla dura 6 —si
 * el asiento falla, la accion no se confirma— la publicacion es lo ultimo antes
 * del commit. Una correccion sin traza es peor que ninguna correccion: cambia
 * las horas de alguien sin decir quien lo hizo.
 *
 * ## Lo que NUNCA hace
 *
 * - **No reintenta.** `RegisterScanHandler` reintenta porque dos escaneos
 *   simultaneos del mismo empleado son normales y el quiosco no puede bloquear a
 *   nadie (regla dura 19). Aqui es al reves: si entre la lectura y la escritura
 *   otra persona corrigio el mismo tramo, reintentar aplicaria la correccion
 *   sobre una version que quien la pidio no ha visto. La restriccion de
 *   exclusion o la desaparicion del tramo vigente abortan la transaccion, y el
 *   segundo responsable recibe un `409` y vuelve a mirar.
 * - **No autoriza.** Quien puede corregir lo decide la policy del endpoint
 *   (regla dura 18). Lo que este caso de uso exige es que haya un autor, porque
 *   sin firma no hay correccion (RN-13).
 * - **No pregunta la hora.** `Clock` da el momento de la correccion; las horas
 *   trabajadas llegan en la orden y pueden ser de hace tres semanas (regla dura
 *   2, ADR-021).
 */
final readonly class CorrectShiftHandler
{
    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayRepository $workDays,
        private ShiftCorrectionLedger $ledger,
        private EventPublisher $events,
        private OperationalSettingsProvider $settings,
        private Clock $clock,
    ) {}

    /**
     * @throws ShiftEntryNotFound el tramo no existe o ya no es vigente
     */
    public function handle(CorrectShiftCommand $command): CorrectedShift
    {
        // Regla dura 9 y RN-13: el momento de la correccion lo fija el servidor
        // una sola vez, y no es ninguna de las dos marcas del tramo.
        $performedAt = $this->clock->now();

        return $this->connection->transaction(
            fn (): CorrectedShift => $this->correct($command, $performedAt),
        );
    }

    private function correct(CorrectShiftCommand $command, DateTimeImmutable $performedAt): CorrectedShift
    {
        $workDay = $this->workDays->findWorkDayOfShiftEntry($command->shiftEntryUuid);

        if (! $workDay instanceof WorkDay) {
            throw ShiftEntryNotFound::withUuid($command->shiftEntryUuid);
        }

        $corrected = $workDay->correctEntry(
            $command->shiftEntryUuid,
            // UUID v7, como el del fichaje: cada version es una fila nueva y
            // `shift_entries.uuid` es unico, asi que la version corregida tiene
            // identificador propio. Lo genera el caso de uso porque lleva la
            // hora dentro y el dominio no pregunta la hora (regla dura 2).
            Str::uuid7()->toString(),
            $this->timesFor($command, $workDay),
            // La marca que cambia pasa a `manual_admin`; la que no, conserva su
            // origen. Ese reparto lo hace el tramo al crear su version
            // siguiente, dentro del agregado.
            ScanOrigin::MANUAL_ADMIN,
            Correction::by($command->performedByUserId, $performedAt, $command->reason),
            ClockingPolicies::forSettings($this->settings->forSite($workDay->siteId())),
        );

        $this->workDays->save($workDay);

        $events = $workDay->releaseEvents();
        $correction = Corrections::in($events);

        // `shift_corrections` es de este modulo y se escribe aqui; `audit_log`
        // es de Compliance y lo escribe su listener al publicarse el evento. Las
        // dos, dentro de esta transaccion.
        $this->ledger->record($correction);
        $this->events->publish(...$events);

        return CorrectedShift::of($workDay, $corrected, $correction);
    }

    /**
     * Las marcas nuevas, compuestas sobre las que el tramo ya tenia.
     *
     * Un `PATCH` que solo trae la salida deja la entrada donde estaba: por eso se
     * parte de `times()` en vez de construir un `ShiftTimes` desde cero con lo
     * que venga en la orden, que dejaria a nulo lo que el cliente no envio y
     * reabriria tramos cerrados sin que nadie lo hubiera pedido.
     */
    private function timesFor(CorrectShiftCommand $command, WorkDay $workDay): ShiftTimes
    {
        $times = $workDay->entry($command->shiftEntryUuid)->times();

        if ($command->clockedInAt !== null) {
            $times = $times->withClockIn($command->clockedInAt);
        }

        if ($command->clockedOutAt !== null) {
            $times = $times->withClockOut($command->clockedOutAt);
        }

        return $times;
    }
}
