<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Model;

use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Attendance\Domain\Exception\CorrectionChangesNothing;
use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\NoOpenShiftEntry;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Exception\ShiftEntryDoesNotBelongToWorkDay;
use App\Modules\Attendance\Domain\Exception\ShiftEntryNotInWorkDay;
use App\Modules\Attendance\Domain\Policy\ClockingPolicy;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionAction;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La **jornada** de un empleado: el conjunto de tramos atribuidos a una fecha
 * civil segun RN-05, y la frontera transaccional del fichaje (doc 01 §5.2).
 *
 * Protege siete invariantes y no delega ninguna:
 *
 * | Invariante | Regla | Donde |
 * |---|---|---|
 * | Un solo turno abierto | RN-01 | `guardNoOpenEntry()`, `guardTimesFit()` |
 * | Sin solapes | RN-02 | `guardDoesNotOverlap*()`, `guardTimesFit()` |
 * | Salida posterior a entrada | RN-03 | `TimeRange`, al cerrar y al corregir |
 * | Todo instante en UTC | RN-04 | `TimeRange::assertUtc()` |
 * | La jornada no cambia de dia | RN-05 | `guardOpeningStaysOnThisWorkDate()` |
 * | Total recalculado | RN-06 | `totalWorked()`, que **suma**, no acumula |
 * | Version nueva, nunca sobrescritura | RN-13 | `correctEntry()`, `voidEntry()` |
 *
 * **El total es un calculo, no un campo.** No existe ningun `$total` que
 * incrementar, y por eso no puede desincronizarse: anular o corregir un tramo
 * cambia el resultado de la siguiente llamada sin que nadie tenga que acordarse
 * de restar (RN-06, ADR-007). `daily_totals` es una proyeccion de esto,
 * reconstruible en cualquier momento.
 *
 * **No conoce el reloj.** Recibe cada instante ya resuelto, que es lo que
 * permite probar los dos cambios de hora de forma determinista (regla dura 2,
 * ADR-021).
 *
 * **No carga tramos historicos.** Un tramo `voided` o `superseded` no forma
 * parte de la jornada: es historia, y el agregado no protege invariantes sobre
 * hechos ya sustituidos (ADR-026).
 *
 * **Tambien es la frontera de la correccion** (RF-PA-04, RN-13, tarea 1.15).
 * Crear, rectificar, cerrar o anular un tramo son cuatro operaciones de la
 * jornada, no del tramo: son exactamente las que pueden dejar dos turnos
 * abiertos (RN-01), dos tramos solapados (RN-02) o un total que no cuadra
 * (RN-06), y quien protege esas tres es esta clase. Un caso de uso que
 * modificase `ShiftEntry` por su cuenta se saltaria las tres a la vez, y el
 * sintoma no aparece hasta que dos tramos solapados llegan a una nomina.
 */
final class WorkDay
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /**
     * Tramos que **han dejado de ser vigentes en esta unidad de trabajo**:
     * anulados y sustituidos (ADR-026).
     *
     * Salen de `$entries` en cuanto se retiran —si no, contarian dos veces en el
     * total, que es el error que ADR-026 existe para evitar— pero el repositorio
     * tiene que escribir su `status` y su `superseded_by_id`, asi que no pueden
     * desaparecer del agregado sin mas. Regla dura 5: nada se borra.
     *
     * @var list<ShiftEntry>
     */
    private array $retiredEntries = [];

    /**
     * @param  list<ShiftEntry>  $entries
     */
    private function __construct(
        private readonly string $employeeUuid,
        private readonly int $siteId,
        private readonly WorkDate $workDate,
        private array $entries,
    ) {}

    /**
     * Abre una jornada nueva, todavia sin tramos.
     *
     * La fecha llega resuelta desde fuera —`WorkDate::fromInstant()` con la zona
     * que sirve `SiteCalendar`— porque RN-05 la define en la zona del centro, y
     * el dominio no consulta de donde sale esa zona.
     */
    public static function start(string $employeeUuid, int $siteId, WorkDate $workDate): self
    {
        return new self($employeeUuid, $siteId, $workDate, []);
    }

    /**
     * Rehidrata una jornada desde la base de datos y **vuelve a comprobar sus
     * invariantes**.
     *
     * No es paranoia: la restriccion de exclusion y el indice unico protegen la
     * tabla, pero un tramo puede llegar aqui desde una importacion o desde una
     * consulta mal filtrada, y cargar dos turnos abiertos sin protestar
     * convertiria RN-01 en una sugerencia el dia que hiciera falta de verdad.
     *
     * @param  list<ShiftEntry>  $entries
     */
    public static function reconstitute(string $employeeUuid, int $siteId, WorkDate $workDate, array $entries): self
    {
        $workDay = new self($employeeUuid, $siteId, $workDate, $entries);

        $workDay->guardEntriesBelongHere();
        $workDay->guardAtMostOneOpenEntry();
        $workDay->guardNoOverlapsAmongEntries();

        return $workDay;
    }

    /**
     * Abre un tramo en esta jornada (RF-AT-02).
     *
     * El `entryUuid` lo genera el caso de uso; el dominio no fabrica
     * identificadores basados en la hora (regla dura 2).
     */
    public function clockIn(string $entryUuid, DateTimeImmutable $at, ScanOrigin $origin): ShiftEntry
    {
        TimeRange::assertUtc('clockedInAt', $at);
        $this->guardNoOpenEntry();
        $this->guardNothingExtendsBeyond($at);

        $entry = ShiftEntry::open($entryUuid, $this->employeeUuid, $this->workDate, $at, $origin);
        $this->entries[] = $entry;

        $this->record(new EmployeeClockedIn(
            $this->employeeUuid,
            $this->siteId,
            $entry->uuid(),
            $this->workDate,
            $at,
            $origin,
        ));
        $this->record($this->dailyTotals($at));

        return $entry;
    }

    /**
     * Cierra el tramo abierto de esta jornada y clasifica su duracion
     * (RF-AT-03, RN-07, RN-08).
     *
     * La politica llega por parametro con sus umbrales ya resueltos: el
     * agregado no sabe de donde salen ni tiene ninguna constante (regla
     * dura 14).
     */
    public function clockOut(DateTimeImmutable $at, ScanOrigin $origin, ClockingPolicy $policy): ShiftEntry
    {
        TimeRange::assertUtc('clockedOutAt', $at);

        $entry = $this->openEntry()
            ?? throw NoOpenShiftEntry::forEmployeeOn($this->employeeUuid, $this->workDate->isoDate);

        $this->guardClosingDoesNotOverlap($entry, $at);

        $entry->close($at, $origin);

        // RN-07 y RN-08. El tramo queda cerrado con sus marcas reales pase lo
        // que pase: la anomalia se marca para que una persona lo revise, nunca
        // para deshacer lo que el empleado ficho.
        $anomalies = $policy->anomaliesFor($entry->workedDuration());

        if ($anomalies !== []) {
            $entry->markAnomalous();
        }

        $this->record(new EmployeeClockedOut(
            $this->employeeUuid,
            $this->siteId,
            $entry->uuid(),
            $this->workDate,
            $entry->clockedInAt(),
            $at,
            $origin,
            $entry->workedDuration(),
            $this->totalWorked(),
            $anomalies,
        ));
        $this->record($this->dailyTotals($at));

        return $entry;
    }

    /**
     * Da de alta un tramo que nunca se ficho (RF-PA-04, accion `created`).
     *
     * Es el olvido de fichaje de entrada, el dia sin tarjeta y la jornada
     * anterior a la puesta en marcha. Pasa por las mismas invariantes que un
     * fichaje —RN-01, RN-02, RN-03— porque un tramo escrito a mano vale para la
     * nomina exactamente igual que uno escaneado; lo unico que cambia es que
     * lleva firma, momento y motivo (RN-13).
     *
     * **No se comprueba que la fecha civil de la entrada sea la de la jornada**,
     * y es deliberado: la vuelta de una pausa a las 02:30 pertenece a la jornada
     * que empezo ayer a las 22:00 (ADR-024, ADR-006). Lo que si se comprueba es
     * que el alta no cambie **el tramo que abre** la jornada a otro dia civil,
     * que es lo que si moveria `work_date` (RN-05).
     */
    public function addEntry(
        string $entryUuid,
        ShiftTimes $times,
        ScanOrigin $source,
        Correction $correction,
        ClockingPolicy $policy,
    ): ShiftEntry {
        $this->guardTimesFit($times, null);
        $this->guardOpeningStaysOnThisWorkDate($times, null);

        $entry = ShiftEntry::declaredManually($entryUuid, $this->employeeUuid, $this->workDate, $times, $source);
        $this->entries[] = $entry;

        $this->recordCorrection(
            CorrectionAction::CREATED,
            $entry,
            null,
            null,
            $times,
            $correction,
            $this->classify($entry, $policy),
        );

        return $entry;
    }

    /**
     * Rectifica las marcas de un tramo **creando una version nueva** (RN-13,
     * RF-PA-04, regla dura 5).
     *
     * Cubre las dos acciones que RF-PA-04 distingue —«modificar hora» y «cerrar
     * turno abierto»— porque desde fuera son la misma peticion; quien las separa
     * es {@see CorrectionAction::betweenTimes()}, mirando si antes habia salida.
     *
     * **La version anterior no se toca.** Sale del conjunto vigente marcada como
     * `superseded` y apuntando a la nueva; sus horas, su origen y su version se
     * quedan donde estaban y siguen siendo consultables (RL-04, ADR-026). El
     * total del dia no hace falta ajustarlo: {@see totalWorked()} suma los
     * vigentes, y la version vieja ya no lo es (RN-06, regla dura 7).
     *
     * **El `replacementUuid` lo genera el caso de uso**, igual que en
     * `clockIn()`: es un UUID v7 y lleva la hora dentro, y el dominio no
     * pregunta la hora (regla dura 2).
     *
     * @throws ShiftEntryNotInWorkDay el tramo no es de esta jornada, o ya fue anulado o sustituido
     * @throws CorrectionChangesNothing las marcas nuevas son las mismas
     * @throws CorrectionWouldChangeWorkDate la correccion moveria la jornada a otro dia civil (RN-05)
     * @throws ShiftAlreadyOpen dejaria dos turnos abiertos (RN-01)
     * @throws OverlappingShiftEntry pisaria a otro tramo vigente (RN-02)
     */
    public function correctEntry(
        string $entryUuid,
        string $replacementUuid,
        ShiftTimes $times,
        ScanOrigin $source,
        Correction $correction,
        ClockingPolicy $policy,
    ): ShiftEntry {
        $entry = $this->entry($entryUuid);
        $before = $entry->times();

        if ($before->equals($times)) {
            throw CorrectionChangesNothing::forEntry($entryUuid);
        }

        // Las guardas van ANTES de tocar nada: un agregado que lanza a mitad de
        // una operacion se queda en un estado que nadie ha decidido, y el caso
        // de uso podria seguir usandolo creyendo que no paso nada.
        $this->guardTimesFit($times, $entry);
        $this->guardOpeningStaysOnThisWorkDate($times, $entry);

        $replacement = $entry->nextVersion($replacementUuid, $times, $source);

        $entry->markSupersededBy($replacementUuid);
        $this->retire($entry);
        $this->entries[] = $replacement;

        $this->recordCorrection(
            CorrectionAction::betweenTimes($before, $times),
            $entry,
            $replacement,
            $before,
            $times,
            $correction,
            $this->classify($replacement, $policy),
        );

        return $replacement;
    }

    /**
     * Anula un tramo: **este tramo no ocurrio** (RF-PA-04, accion `voided`,
     * ADR-026).
     *
     * No crea version nueva y no pone `superseded_by_id`, porque no hay una
     * version posterior de un hecho que no paso. La fila se queda en la tabla
     * con sus marcas —regla dura 5— y sale del conjunto vigente, con lo que
     * libera el hueco para RN-01 y RN-02 y desaparece del total del dia.
     *
     * **No cambia la `work_date` de la jornada** aunque el tramo anulado fuera
     * el que la abrio. La jornada es una fecha civil con su fila en
     * `daily_totals`; recalcularla al anular moveria minutos entre dias sin que
     * nadie lo hubiera pedido, y dejaria una fila huerfana en la proyeccion.
     *
     * @throws ShiftEntryNotInWorkDay el tramo no es de esta jornada, o ya estaba anulado o sustituido
     */
    public function voidEntry(string $entryUuid, Correction $correction): ShiftEntry
    {
        $entry = $this->entry($entryUuid);
        $before = $entry->times();

        $entry->markVoided();
        $this->retire($entry);

        $this->recordCorrection(
            CorrectionAction::VOIDED,
            $entry,
            null,
            $before,
            null,
            $correction,
            [],
        );

        return $entry;
    }

    /**
     * RN-06: el total **se recalcula** como suma de los tramos de la jornada.
     *
     * Nunca se incrementa. Es un metodo y no un campo precisamente para que no
     * exista la posibilidad de acumular.
     */
    public function totalWorked(): WorkedDuration
    {
        $total = WorkedDuration::zero();

        foreach ($this->entries as $entry) {
            $total = $total->plus($entry->workedDuration());
        }

        return $total;
    }

    public function employeeUuid(): string
    {
        return $this->employeeUuid;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    public function workDate(): WorkDate
    {
        return $this->workDate;
    }

    /**
     * Los tramos **vigentes** de la jornada.
     *
     * @return list<ShiftEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Los tramos que han dejado de ser vigentes en esta unidad de trabajo:
     * anulados y sustituidos.
     *
     * **El repositorio tiene que escribirlos** con su `status` nuevo y su
     * `superseded_by_id`, en la misma transaccion y **antes** de insertar la
     * version que los sustituye: mientras la version vieja siga siendo vigente
     * para PostgreSQL, la nueva pisa su intervalo y `shift_entries_no_overlap`
     * aborta la correccion (ADR-026).
     *
     * @return list<ShiftEntry>
     */
    public function retiredEntries(): array
    {
        return $this->retiredEntries;
    }

    public function shiftCount(): int
    {
        return \count($this->entries);
    }

    public function openEntry(): ?ShiftEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->isOpen()) {
                return $entry;
            }
        }

        return null;
    }

    public function hasOpenEntry(): bool
    {
        return $this->openEntry() instanceof ShiftEntry;
    }

    public function hasAnomaly(): bool
    {
        return array_any(
            $this->entries,
            static fn (ShiftEntry $entry): bool => $entry->status() === ShiftEntryStatus::ANOMALOUS,
        );
    }

    /**
     * Primera entrada de la jornada, para `daily_totals.first_in_at`.
     */
    public function firstClockInAt(): ?DateTimeImmutable
    {
        $first = null;

        foreach ($this->entries as $entry) {
            if ($first === null || $entry->clockedInAt() < $first) {
                $first = $entry->clockedInAt();
            }
        }

        return $first;
    }

    /**
     * Ultima salida de la jornada, para `daily_totals.last_out_at`. Nula
     * mientras quede un tramo abierto sin cerrar por delante.
     */
    public function lastClockOutAt(): ?DateTimeImmutable
    {
        $last = null;

        foreach ($this->entries as $entry) {
            $out = $entry->clockedOutAt();

            if ($out !== null && ($last === null || $out > $last)) {
                $last = $out;
            }
        }

        return $last;
    }

    /**
     * Entrega los eventos acumulados y deja el agregado limpio.
     *
     * Los publica el caso de uso **despues** de que la transaccion confirme: un
     * evento emitido por una escritura que luego revierte deja al panel en vivo
     * y a la auditoria contando algo que no ocurrio.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    private function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * El evento de la correccion y el recalculo del dia, en ese orden y siempre
     * los dos.
     *
     * Van juntos a proposito: no existe una correccion que no cambie el total de
     * la jornada —anular quita minutos, corregir los cambia, un alta los suma— y
     * separar las dos llamadas dejaria abierta la puerta a que una accion futura
     * se olvidara de la segunda y `daily_totals` se quedara contando lo de antes
     * (RN-06, regla dura 7).
     *
     * @param  list<ShiftAnomaly>  $anomalies
     */
    private function recordCorrection(
        CorrectionAction $action,
        ShiftEntry $entry,
        ?ShiftEntry $replacement,
        ?ShiftTimes $before,
        ?ShiftTimes $after,
        Correction $correction,
        array $anomalies,
    ): void {
        $this->record(new ShiftCorrected(
            employeeUuid: $this->employeeUuid,
            siteId: $this->siteId,
            workDate: $this->workDate,
            action: $action,
            shiftEntryUuid: $entry->uuid(),
            shiftEntryVersion: $entry->version(),
            replacementShiftEntryUuid: $replacement?->uuid(),
            replacementVersion: $replacement?->version(),
            before: $before,
            after: $after,
            correction: $correction,
            dailyTotal: $this->totalWorked(),
            anomalies: $anomalies,
        ));

        // El recalculo se fecha en el momento de la correccion, que es lo que
        // provoco el cambio. No es la hora a la que se trabajo.
        $this->record($this->dailyTotals($correction->performedAt));
    }

    /**
     * RN-07 y RN-08 sobre la version resultante de una correccion.
     *
     * Un tramo corregido se vuelve a clasificar por la misma politica que uno
     * fichado: si un responsable rectifica una salida y deja catorce horas de
     * tramo, eso pide revision humana igual que si lo hubiera producido el
     * quiosco. Lo contrario abriria un camino para colar duraciones imposibles
     * sin que nadie las mirara.
     *
     * Un tramo abierto no se clasifica: todavia no tiene duracion.
     *
     * @return list<ShiftAnomaly>
     */
    private function classify(ShiftEntry $entry, ClockingPolicy $policy): array
    {
        if ($entry->isOpen()) {
            return [];
        }

        $anomalies = $policy->anomaliesFor($entry->workedDuration());

        if ($anomalies !== []) {
            $entry->markAnomalous();
        }

        return $anomalies;
    }

    /**
     * El tramo vigente con ese identificador.
     *
     * Que no aparezca significa una de dos: no es de esta jornada, o ya no es
     * vigente porque lo anularon o lo sustituyeron (ADR-026). Las dos se
     * responden igual desde aqui —el tramo no esta— y quien llama las distingue
     * con el historico si le hace falta.
     *
     * Es publico y de **solo lectura**: el caso de uso lo necesita para componer
     * las marcas nuevas sobre las que ya hay —un `PATCH` que solo trae la hora de
     * salida deja la de entrada donde estaba— y `ShiftEntry` no deja tocar nada
     * desde fuera del agregado, lo que verifica `AggregateBoundaryTest`.
     */
    public function entry(string $entryUuid): ShiftEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->uuid() === $entryUuid) {
                return $entry;
            }
        }

        throw ShiftEntryNotInWorkDay::withUuid($entryUuid, $this->workDate->isoDate);
    }

    /**
     * Saca un tramo del conjunto vigente y lo guarda para que el repositorio
     * escriba su estado nuevo.
     */
    private function retire(ShiftEntry $entry): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (ShiftEntry $candidate): bool => $candidate !== $entry,
        ));

        $this->retiredEntries[] = $entry;
    }

    /**
     * RN-01 y RN-02 para unas marcas que todavia no son un tramo.
     *
     * `$excluding` es el tramo que estas marcas van a sustituir: no puede
     * solapar consigo mismo, porque en cuanto la correccion se aplique dejara de
     * ser vigente. En un alta manual no hay ninguno.
     */
    private function guardTimesFit(ShiftTimes $times, ?ShiftEntry $excluding): void
    {
        foreach ($this->entries as $entry) {
            if ($entry === $excluding) {
                continue;
            }

            // RN-01: un solo turno abierto por empleado. La misma invariante que
            // el indice unico parcial, aqui con el lenguaje del negocio.
            if ($times->isOpen() && $entry->isOpen()) {
                throw ShiftAlreadyOpen::forEmployee($this->employeeUuid);
            }

            if ($this->overlaps($times, $entry)) {
                throw OverlappingShiftEntry::at($this->employeeUuid, $times->clockedInAt);
            }
        }
    }

    /**
     * RN-02 entre unas marcas y un tramo, con la semantica `[inicio, fin)` de la
     * restriccion de exclusion. Un tramo sin salida llega hasta el infinito.
     */
    private function overlaps(ShiftTimes $times, ShiftEntry $entry): bool
    {
        $period = $times->period();
        $entryPeriod = $entry->period();

        return match (true) {
            // Dos tramos sin fin siempre se pisan. RN-01 lo habra rechazado
            // antes; esto lo deja escrito para que no dependa de aquel orden.
            $period === null && $entryPeriod === null => true,
            $period === null => $entry->extendsBeyond($times->clockedInAt),
            $entryPeriod === null => $period->endsAfter($entry->clockedInAt()),
            default => $period->overlaps($entryPeriod),
        };
    }

    /**
     * RN-05 y regla dura 4: una correccion no puede mover la jornada a otro dia
     * civil.
     *
     * `work_date` es la fecha civil, en la zona del centro, de la entrada del
     * tramo que **abre** la jornada. Por eso la comprobacion no mira el tramo
     * corregido sino cual seria la entrada mas temprana **despues** de aplicar
     * la correccion: rectificar la salida de un turno 22:00 -> 06:00 no la toca,
     * y corregir la vuelta de una pausa a las 02:30 tampoco, porque ninguno de
     * los dos es el que abrio el dia (ADR-006, ADR-024).
     *
     * Mover la entrada que si abre la jornada al otro lado de la medianoche
     * local llevaria esas horas a **otra jornada**, es decir, a otro agregado y
     * a otra fila de `daily_totals`. Este agregado no puede protegerlas alli, y
     * por eso se niega: se anula aqui y se da de alta alla, dos acciones
     * explicitas y auditadas.
     */
    private function guardOpeningStaysOnThisWorkDate(ShiftTimes $times, ?ShiftEntry $excluding): void
    {
        $opening = $times->clockedInAt;

        foreach ($this->entries as $entry) {
            if ($entry === $excluding) {
                continue;
            }

            if ($entry->clockedInAt() < $opening) {
                $opening = $entry->clockedInAt();
            }
        }

        $resulting = WorkDate::fromInstant($opening, $this->workDate->timezone);

        if (! $resulting->equals($this->workDate)) {
            throw CorrectionWouldChangeWorkDate::from(
                $excluding?->uuid() ?? 'new',
                $this->workDate->isoDate,
                $resulting->isoDate,
            );
        }
    }

    private function dailyTotals(DateTimeImmutable $at): DailyTotalsRecalculated
    {
        return new DailyTotalsRecalculated(
            $this->employeeUuid,
            $this->workDate,
            $this->totalWorked(),
            $this->shiftCount(),
            $this->firstClockInAt(),
            $this->lastClockOutAt(),
            $this->hasOpenEntry(),
            $this->hasAnomaly(),
            $at,
        );
    }

    /**
     * RN-01. La misma invariante que el indice unico parcial
     * `one_open_shift_per_employee`, aqui para que el error llegue con un
     * mensaje del negocio y no como una violacion de restriccion.
     */
    private function guardNoOpenEntry(): void
    {
        if ($this->hasOpenEntry()) {
            throw ShiftAlreadyOpen::forEmployee($this->employeeUuid);
        }
    }

    /**
     * RN-02 al abrir: el tramo nuevo no tiene fin, asi que solapa con cualquier
     * tramo que siga vivo despues de su inicio. Es el caso de un lote offline
     * que se sincroniza tarde y trae un escaneo anterior a lo ya registrado.
     */
    private function guardNothingExtendsBeyond(DateTimeImmutable $at): void
    {
        foreach ($this->entries as $entry) {
            if ($entry->extendsBeyond($at)) {
                throw OverlappingShiftEntry::at($this->employeeUuid, $at);
            }
        }
    }

    /**
     * RN-02 al cerrar: el intervalo que queda definido no puede pisar a ningun
     * otro tramo de la jornada.
     */
    private function guardClosingDoesNotOverlap(ShiftEntry $closing, DateTimeImmutable $at): void
    {
        $closed = new TimeRange($closing->clockedInAt(), $at);

        foreach ($this->entries as $entry) {
            if ($entry === $closing) {
                continue;
            }

            $period = $entry->period();

            if ($period === null || $period->overlaps($closed)) {
                throw OverlappingShiftEntry::at($this->employeeUuid, $at);
            }
        }
    }

    private function guardEntriesBelongHere(): void
    {
        foreach ($this->entries as $entry) {
            if ($entry->employeeUuid() !== $this->employeeUuid) {
                throw ShiftEntryDoesNotBelongToWorkDay::becauseOfEmployee($entry->uuid(), $this->employeeUuid);
            }

            if (! $entry->workDate()->equals($this->workDate)) {
                throw ShiftEntryDoesNotBelongToWorkDay::becauseOfWorkDate($entry->uuid(), $this->workDate->isoDate);
            }

            if (! $entry->status()->isCurrent()) {
                throw ShiftEntryDoesNotBelongToWorkDay::becauseItIsNotCurrent($entry->uuid(), $entry->status()->value);
            }
        }
    }

    private function guardAtMostOneOpenEntry(): void
    {
        $open = 0;

        foreach ($this->entries as $entry) {
            if ($entry->isOpen()) {
                $open++;
            }
        }

        if ($open > 1) {
            throw ShiftAlreadyOpen::forEmployee($this->employeeUuid);
        }
    }

    private function guardNoOverlapsAmongEntries(): void
    {
        $count = \count($this->entries);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $this->guardPairDoesNotOverlap($this->entries[$i], $this->entries[$j]);
            }
        }
    }

    private function guardPairDoesNotOverlap(ShiftEntry $one, ShiftEntry $other): void
    {
        $onePeriod = $one->period();
        $otherPeriod = $other->period();

        // Con un tramo abierto en la pareja basta con mirar si el otro sigue
        // vivo despues de que aquel empiece: un tramo sin fin llega al infinito.
        $overlaps = match (true) {
            $onePeriod === null => $other->extendsBeyond($one->clockedInAt()),
            $otherPeriod === null => $one->extendsBeyond($other->clockedInAt()),
            default => $onePeriod->overlaps($otherPeriod),
        };

        if ($overlaps) {
            throw OverlappingShiftEntry::at($this->employeeUuid, $one->clockedInAt());
        }
    }
}
