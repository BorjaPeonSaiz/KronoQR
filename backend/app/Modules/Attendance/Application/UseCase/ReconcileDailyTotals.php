<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\ReconcileDailyTotalsCommand;
use App\Modules\Attendance\Application\Exception\InvalidReconciliationRange;
use App\Modules\Attendance\Application\Port\DailyTotalsProjection;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ProjectedDailyTotal;
use App\Modules\Attendance\Application\Port\ProjectionMetrics;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Event\DailyTotalsReconciled;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La reconciliacion de `daily_totals` con sus eventos origen (RF-PR-02,
 * ADR-007, tarea 2.7).
 *
 * ## Que es esto y que no es
 *
 * `daily_totals` es una **proyeccion reconstruible** que se reescribe entera en
 * la misma transaccion que la escritura que la motiva (regla dura 7, RN-06). Si
 * esa garantia se cumple, esta pasada no tiene nada que corregir **nunca**: por
 * eso `projection_divergence_total` debe permanecer siempre en cero (doc 02
 * §8.2) y por eso el comando termina con codigo distinto de cero cuando
 * encuentra algo, aunque lo haya arreglado. Una divergencia no es mantenimiento
 * rutinario: es la señal de que algo escribio la proyeccion por un camino que no
 * es el recalculo, o de que alguien toco la tabla a mano.
 *
 * **No es la red de seguridad que permite relajar la regla dura 7**, es su
 * detector. Corregir en silencio y seguir seria convertir el sintoma en una
 * tarea de limpieza nocturna.
 *
 * ## La aritmetica es del dominio, no de aqui
 *
 * El estado correcto se recompone cargando la jornada por su puerto de lectura y
 * preguntandole a `WorkDay`: `totalWorked()`, `shiftCount()`, `firstClockInAt()`,
 * `lastClockOutAt()`, `hasOpenEntry()` y `hasAnomaly()`. Ni una suma en SQL
 * (regla dura 1): si la reconciliacion tuviera formula propia, compararia dos
 * calculos distintos y el dia que discreparan no habria forma de saber cual de
 * los dos esta mal.
 *
 * El conjunto vigente —sin `voided` ni `superseded`— lo aplica el propio
 * `WorkDayLedger` (ADR-026), que es el mismo que usa la revision diaria: dos
 * predicados serian dos definiciones de «vigente».
 *
 * ## Un dia, un lote; una jornada divergente, una transaccion
 *
 * Se revisa por fechas civiles, y **cada jornada divergente se corrige en su
 * propia transaccion**: la reescritura de la fila y el asiento de auditoria
 * entran juntos o no entra ninguno (regla dura 6). Una unica transaccion para un
 * rango de un mes mantendria un candado sobre la proyeccion durante toda la
 * pasada y perderia las correcciones ya hechas si la ultima fallara. Que cada
 * jornada sea independiente es tambien lo que permite terminar informando de lo
 * que fallo en vez de morir a la mitad.
 *
 * La reescritura la hace el **mismo** listener que la del fichaje: se publica
 * `DailyTotalsRecalculated` con el estado completo y `DailyTotalsProjector`
 * escribe. Aqui no hay ningun `UPDATE daily_totals`, y es deliberado: dos
 * caminos de escritura serian dos oportunidades de divergir.
 *
 * ## Filas sin tramos vigentes
 *
 * Una jornada anulada por completo deja fila en la proyeccion y ningun tramo
 * detras. **Se pone a cero, no se borra** (regla dura 5): el dia siguio
 * existiendo aunque su contenido se anulara, y borrarlo haria desaparecer del
 * panel una jornada sobre la que alguien tomo una decision. Es ademas lo que ya
 * hace el agregado, que no cambia su `work_date` al anular el tramo que la abrio.
 */
final readonly class ReconcileDailyTotals
{
    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayLedger $workDays,
        private DailyTotalsProjection $projection,
        private InstallationSiteProvider $sites,
        private EventPublisher $events,
        private ProjectionMetrics $metrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(ReconcileDailyTotalsCommand $command): ReconciliationReport
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return ReconciliationReport::withoutSite();
        }

        $now = $this->clock->now();
        $timezone = new DateTimeZone($site->timezone);
        [$from, $to] = $this->range($command, $timezone, $now);
        $dates = $this->datesIn($from, $to, $timezone);

        $workDaysInspected = 0;
        $divergences = 0;
        $corrected = 0;
        $failures = 0;
        $byField = [];

        foreach ($dates as $date) {
            foreach ($this->inspect($date, $now, $workDaysInspected, $failures) as $divergence) {
                $divergences++;

                foreach ($divergence->fields as $field) {
                    $byField[$field] = ($byField[$field] ?? 0) + 1;
                }

                if ($this->correct($divergence, $now)) {
                    $corrected++;

                    continue;
                }

                $failures++;
            }
        }

        return $this->publish(
            ReconciliationReport::of(
                fromIsoDate: $from->isoDate,
                toIsoDate: $to->isoDate,
                daysInspected: \count($dates),
                workDaysInspected: $workDaysInspected,
                divergences: $divergences,
                corrected: $corrected,
                failures: $failures,
                byField: $byField,
            ),
            $now,
        );
    }

    /**
     * Contrasta una fecha civil completa y devuelve lo que no cuadra.
     *
     * **Dos lecturas por dia y no una por empleado**: los tramos vigentes de la
     * fecha por un lado y las filas de la proyeccion por otro. Sobre una
     * plantilla de doscientas personas, preguntar jornada a jornada serian
     * cuatrocientas consultas para leer lo mismo.
     *
     * @param  int  $workDaysInspected  se incrementa con cada jornada contrastada
     * @param  int  $failures  se incrementa si la fecha entera no se pudo leer
     * @return list<DailyTotalsDivergence>
     */
    private function inspect(WorkDate $date, DateTimeImmutable $now, int &$workDaysInspected, int &$failures): array
    {
        try {
            $workDays = $this->workDays->workDaysBetween($date, $date);
            $projected = $this->projectedByEmployee($date);
        } catch (Throwable $failure) {
            // Un dia ilegible no aborta la pasada: `WorkDay::reconstitute()`
            // vuelve a comprobar las invariantes, y una importacion que dejo dos
            // turnos abiertos hace tres meses no puede impedir que se reconcilie
            // ayer. Se cuenta como fallo y el comando termina en rojo.
            $failures++;

            $this->logger->error('attendance.projection_reconciliation_failed', [
                'work_date' => $date->isoDate,
                'exception' => $failure::class,
            ]);

            return [];
        }

        $divergences = [];
        $seen = [];

        foreach ($workDays as $workDay) {
            $workDaysInspected++;
            $employeeUuid = $workDay->employeeUuid();
            $seen[$employeeUuid] = true;

            $divergence = DailyTotalsDivergence::between(
                $this->expectedFor($workDay, $now),
                $projected[$employeeUuid] ?? null,
            );

            if ($divergence instanceof DailyTotalsDivergence) {
                $divergences[] = $this->announce($divergence);
            }
        }

        foreach ($projected as $employeeUuid => $row) {
            if (isset($seen[$employeeUuid])) {
                continue;
            }

            // Fila en la proyeccion sin ningun tramo vigente detras: la jornada
            // se anulo entera. Cuenta como jornada revisada, y lo que se escribe
            // es un dia a cero.
            $workDaysInspected++;

            $divergence = DailyTotalsDivergence::between(
                $this->emptyFor($employeeUuid, $date, $now),
                $row,
            );

            if ($divergence instanceof DailyTotalsDivergence) {
                $divergences[] = $this->announce($divergence);
            }
        }

        return $divergences;
    }

    /**
     * Reescribe la fila divergente y deja traza de la correccion, las dos cosas
     * en la misma transaccion. Devuelve si lo consiguio.
     *
     * Se publican dos eventos y cada uno tiene su destinatario:
     * `DailyTotalsRecalculated` lo recoge el proyector —el unico camino de
     * escritura de la tabla— y `DailyTotalsReconciled` lo recoge `Compliance`
     * para sellar el asiento de `audit_log` con el valor anterior y el nuevo
     * (regla dura 6). Si el asiento falla, la reescritura revierte: es preferible
     * una fila que sigue mintiendo a una corregida de la que no queda constancia.
     */
    private function correct(DailyTotalsDivergence $divergence, DateTimeImmutable $now): bool
    {
        try {
            $this->connection->transaction(function () use ($divergence, $now): void {
                $this->events->publish(
                    $divergence->expected,
                    $this->reconciled($divergence, $now),
                );
            });

            return true;
        } catch (Throwable $failure) {
            $this->logger->error('attendance.projection_not_corrected', [
                'employee_uuid' => $divergence->employeeUuid(),
                'work_date' => $divergence->workDate(),
                'fields' => $divergence->fields,
                'exception' => $failure::class,
            ]);

            return false;
        }
    }

    /**
     * Deja la diferencia en el log **antes** de escribir nada.
     *
     * No es un adorno: en cuanto la fila se reescribe, la unica prueba de lo que
     * la proyeccion afirmaba esta aqui y en el asiento de auditoria. Sin este
     * apunte, a la mañana siguiente se sabria que hubo una divergencia y no cual
     * era.
     *
     * `employee_uuid`, nunca nombres (regla dura 21): esto viaja a Loki y de ahi
     * al paquete de diagnostico (ADR-020).
     */
    private function announce(DailyTotalsDivergence $divergence): DailyTotalsDivergence
    {
        $this->logger->warning('attendance.projection_divergence', [
            'employee_uuid' => $divergence->employeeUuid(),
            'work_date' => $divergence->workDate(),
            'fields' => $divergence->fields,
            'row_was_missing' => $divergence->rowWasMissing(),
            'projected_total_minutes' => $divergence->actual?->totalMinutes,
            'expected_total_minutes' => $divergence->expected->total->minutes,
            'projected_shift_count' => $divergence->actual?->shiftCount,
            'expected_shift_count' => $divergence->expected->shiftCount,
        ]);

        return $divergence;
    }

    /**
     * El estado que la proyeccion **deberia** tener, preguntado al agregado.
     *
     * Los seis campos salen de seis metodos del dominio. Este caso de uso no
     * suma, no ordena y no decide que tramo abre la jornada: solo transporta
     * (regla dura 1).
     */
    private function expectedFor(WorkDay $workDay, DateTimeImmutable $now): DailyTotalsRecalculated
    {
        return new DailyTotalsRecalculated(
            $workDay->employeeUuid(),
            $workDay->workDate(),
            $workDay->totalWorked(),
            $workDay->shiftCount(),
            $workDay->firstClockInAt(),
            $workDay->lastClockOutAt(),
            $workDay->hasOpenEntry(),
            $workDay->hasAnomaly(),
            $now,
        );
    }

    /**
     * El estado de una jornada sin ningun tramo vigente: cero horas, cero
     * tramos, sin marcas y sin turno abierto.
     *
     * No se construye un `WorkDay` vacio a proposito. El agregado representa una
     * jornada que existe; lo que hay aqui es una fila de una proyeccion que ya no
     * describe nada, y darle forma de agregado invitaria a guardarla.
     */
    private function emptyFor(string $employeeUuid, WorkDate $date, DateTimeImmutable $now): DailyTotalsRecalculated
    {
        return new DailyTotalsRecalculated(
            $employeeUuid,
            $date,
            WorkedDuration::zero(),
            0,
            null,
            null,
            false,
            false,
            $now,
        );
    }

    private function reconciled(DailyTotalsDivergence $divergence, DateTimeImmutable $now): DailyTotalsReconciled
    {
        return new DailyTotalsReconciled(
            employeeUuid: $divergence->employeeUuid(),
            workDate: $divergence->expected->workDate,
            divergentFields: $divergence->fields,
            rowWasMissing: $divergence->rowWasMissing(),
            previousTotalMinutes: $divergence->actual?->totalMinutes,
            previousShiftCount: $divergence->actual?->shiftCount,
            totalMinutes: $divergence->expected->total->minutes,
            shiftCount: $divergence->expected->shiftCount,
            reconciledAt: $now,
        );
    }

    /**
     * Publica la metrica y el resumen de la pasada.
     *
     * **Se publican tambien —y sobre todo— las pasadas limpias.** Una serie que
     * solo aparece cuando algo va mal es indistinguible de una tarea que dejo de
     * ejecutarse, y el silencio es el peor de los fallos: sin el sello de tiempo,
     * apagar el planificador seria la forma mas comoda de que la alerta de
     * divergencia no volviera a sonar nunca.
     */
    private function publish(ReconciliationReport $report, DateTimeImmutable $now): ReconciliationReport
    {
        $this->metrics->reconciliationCompleted(
            $report->workDaysInspected,
            $report->divergences,
            $report->corrected,
            $now,
        );

        $this->logger->info('attendance.projection_reconciliation', [
            'from' => $report->fromIsoDate,
            'to' => $report->toIsoDate,
            'days_inspected' => $report->daysInspected,
            'work_days_inspected' => $report->workDaysInspected,
            'divergences' => $report->divergences,
            'corrected' => $report->corrected,
            'failures' => $report->failures,
            'by_field' => $report->byField,
        ]);

        return $report;
    }

    /**
     * Las filas de la proyeccion de esa fecha, indexadas por empleado.
     *
     * @return array<string, ProjectedDailyTotal>
     */
    private function projectedByEmployee(WorkDate $date): array
    {
        $rows = [];

        foreach ($this->projection->between($date, $date) as $row) {
            $rows[$row->employeeUuid] = $row;
        }

        return $rows;
    }

    /**
     * El rango efectivo. Sin fechas, **ayer**: es el camino del planificador, y
     * la jornada de ayer es la ultima que ya no va a cambiar por si sola.
     *
     * «Ayer» se calcula en la zona del centro y no en UTC. A las 03:50 UTC las
     * dos coinciden en un centro peninsular, pero no en uno al oeste, y ahi la
     * pasada estaria reconciliando un dia que todavia no ha terminado (RN-05).
     *
     * @return array{WorkDate, WorkDate}
     */
    private function range(
        ReconcileDailyTotalsCommand $command,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): array {
        $yesterday = $now->setTimezone($timezone)->modify('-1 day')->format('Y-m-d');

        $from = WorkDate::fromIsoDate($command->fromIsoDate ?? $yesterday, $timezone);
        // Sin `--to`, el rango es un solo dia: el de `--from`. Extenderlo hasta
        // hoy por comodidad revisaria dias que nadie pidio.
        $to = WorkDate::fromIsoDate($command->toIsoDate ?? $command->fromIsoDate ?? $yesterday, $timezone);

        if ($to->isoDate < $from->isoDate) {
            throw InvalidReconciliationRange::endsBeforeItStarts($from->isoDate, $to->isoDate);
        }

        return [$from, $to];
    }

    /**
     * Las fechas civiles del rango, ambas incluidas.
     *
     * Se recorren como **fechas** y no como instantes: sumar 24 horas a un
     * instante se salta un dia el domingo de octubre y repite uno el de marzo.
     * `modify('+1 day')` sobre una fecha en UTC avanza el calendario, que es
     * exactamente lo que RN-05 pide.
     *
     * @return list<WorkDate>
     */
    private function datesIn(WorkDate $from, WorkDate $to, DateTimeZone $timezone): array
    {
        $utc = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($from->isoDate, $utc);
        $last = new DateTimeImmutable($to->isoDate, $utc);

        $dates = [];

        while ($cursor <= $last) {
            $dates[] = WorkDate::fromIsoDate($cursor->format('Y-m-d'), $timezone);
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }
}
