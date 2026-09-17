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
use App\Modules\Attendance\Domain\Event\DailyTotalsSnapshot;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
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
 * ## Inspeccionar es sospechar; corregir es confirmar
 *
 * La pasada de inspeccion **no puede** concluir nada por si sola: lee el
 * registro horario y la proyeccion en dos consultas distintas y sin instantanea
 * comun, asi que un fichaje que confirme entre las dos le deja una mitad nueva y
 * otra vieja. Por eso lo que devuelve son **sospechas**, y la unica lectura que
 * decide es la que {@see correct()} hace con la fila bloqueada dentro de su
 * transaccion. Lo que ahi deja de divergir se cuenta aparte —«resueltas
 * solas»— y **no** sube `projection_divergence_total`: si contara, la alerta
 * critica de integridad sonaria cada madrugada en cualquier hotel con turno de
 * noche, y una alerta que suena siempre no la mira nadie.
 *
 * Esto no relaja nada de lo anterior. Una divergencia **confirmada** sigue
 * siendo un incidente que no deberia poder ocurrir; lo que se ha quitado de en
 * medio es el falso positivo que la propia pasada se fabricaba.
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
        /**
         * La transaccion de cada correccion, **con el candado de la cadena de
         * auditoria ya tomado** (ver {@see correct()}).
         *
         * No es un `ConnectionInterface` a proposito: el orden de los candados
         * es una garantia del producto y no un detalle que cada caso de uso
         * recuerde. Quien la ofrece es un puerto de `Shared` porque el candado
         * lo comparte con `Compliance`, que es quien escribe la cadena.
         */
        private SerializedLedgerWrite $serialized,
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
        $selfResolved = 0;
        $byField = [];

        foreach ($dates as $date) {
            foreach ($this->inspect($date, $now, $workDaysInspected, $failures) as $suspicion) {
                $attempt = $this->correct($suspicion, $now);

                // **Cada desenlace se cuenta donde dice `CorrectionOutcome`**, y
                // los cuatro cuentan distinto:
                //
                //   · La sospecha que el candado no confirma no es una
                //     divergencia: era la lectura de la pasada quedandose vieja
                //     mientras alguien fichaba. Si contara,
                //     `projection_divergence_total` —la alerta critica de
                //     integridad— sonaria cada madrugada en cualquier hotel con
                //     turno de noche.
                //   · La contencion tampoco: nadie llego a comparar nada, asi
                //     que no dice nada sobre la integridad de la tabla. Queda
                //     como fallo, que es lo que de verdad ocurrio.
                //   · Solo lo corregido y lo que fallo teniendo divergencia
                //     delante suben el contador y salen agrupados por columna.
                if ($attempt->outcome->countsAsDivergence()) {
                    $divergences++;

                    // Las columnas son las de la divergencia **confirmada**, no
                    // las que vio la inspeccion: lo que se informa tiene que ser
                    // lo que se escribio.
                    foreach ($attempt->divergentFields() as $field) {
                        $byField[$field] = ($byField[$field] ?? 0) + 1;
                    }
                }

                if ($attempt->outcome === CorrectionOutcome::Corrected) {
                    $corrected++;
                }

                if ($attempt->outcome === CorrectionOutcome::ResolvedItself) {
                    $selfResolved++;
                }

                if ($attempt->outcome->countsAsFailure()) {
                    $failures++;
                }
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
                selfResolved: $selfResolved,
                byField: $byField,
            ),
            $now,
        );
    }

    /**
     * Contrasta una fecha civil completa y devuelve lo que **parece** no cuadrar.
     *
     * Son sospechas y no veredictos: las confirma o las descarta
     * {@see correct()} releyendo con la fila bloqueada. Ver «Inspeccionar es
     * sospechar» en la cabecera de la clase.
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
                $divergences[] = $divergence;
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
                $divergences[] = $divergence;
            }
        }

        return $divergences;
    }

    /**
     * Vuelve a mirar la jornada **con la fila bloqueada** y, si sigue
     * divergiendo, la reescribe y deja traza de la correccion — las dos cosas en
     * la misma transaccion.
     *
     * ## Por que hay que releer, y por que no bastaba con no hacerlo
     *
     * La inspeccion lee dos cosas en dos consultas y sin instantanea comun: los
     * tramos vigentes del dia por un lado y las filas de la proyeccion por otro.
     * Un fichaje que confirma **entre** las dos —o despues de las dos y antes de
     * esta transaccion— escribe su tramo y su fila a la vez (ADR-007), asi que
     * deja a la pasada con una mitad nueva y otra vieja: eso **parece** una
     * divergencia y no lo es. Escribirla de vuelta con la lectura vieja es lo que
     * corrompia la proyeccion — un empleado con su tramo abierto y
     * `shift_count = 0`, otro con el turno ya cerrado y `total_minutes = 0` con
     * `has_open_shift = true`—, y ocurria de verdad: la reconciliacion corre a
     * las 03:50 UTC, que en un hotel es hora punta del turno de noche.
     *
     * ## Por que el candado basta
     *
     * El fichaje escribe el tramo y hace el `UPSERT` de `daily_totals` dentro de
     * la **misma** transaccion, de modo que una jornada confirmada siempre tiene
     * su fila. Tomarla con `FOR UPDATE` serializa esta correccion con ese
     * `UPSERT`: o se toma antes —y entonces el fichaje espera a que esta
     * transaccion termine y **reescribe despues con sus propios valores**, que
     * son los buenos porque su tramo ya esta escrito— o se toma despues, y
     * entonces lo que se relee ya incluye el fichaje y no hay divergencia que
     * corregir. En los dos ordenes el ultimo en escribir lo hace con el registro
     * horario completo delante.
     *
     * La relectura de la jornada va **detras** del candado a proposito: en
     * READ COMMITTED cada sentencia toma instantanea nueva, asi que un `SELECT`
     * posterior a un `FOR UPDATE` que tuvo que esperar ve ya lo que confirmo
     * quien lo hizo esperar.
     *
     * ## El orden de los candados, que es el del fichaje y no otro
     *
     * **Primero el candado global de la cadena de auditoria y despues la fila**,
     * envolviendo todo con `SerializedLedgerWrite::withChainLock()`. Ese es el
     * orden que toma el camino del fichaje, y no por casualidad: el agregado
     * registra primero el hecho —`EmployeeClockedIn`/`Out`, que `Compliance`
     * sella tomando el candado— y despues `DailyTotalsRecalculated`, que es el
     * `UPSERT` de la fila.
     *
     * Hacerlo al reves —la fila primero, el asiento al publicar— cerraba un
     * ciclo con el fichaje, y eso no es una carrera que se resuelva
     * reintentando: es un abrazo mortal que PostgreSQL rompe matando a una de
     * las dos transacciones a `deadlock_timeout`. La victima podia ser el
     * fichaje, es decir, un empleado recibiendo un error al pasar la tarjeta
     * porque una tarea de mantenimiento estaba reconciliando (regla dura 19).
     *
     * El candado consultivo es reentrante en la misma transaccion, asi que el
     * asiento que se escribe al final vuelve a pedirlo y no espera. **El coste
     * es que el candado global se retiene durante la correccion de cada fila**
     * —milisegundos, y solo cuando hay una divergencia de verdad—: por eso se
     * envuelve una jornada y nunca el recorrido del dia.
     *
     * ## Contencion no es divergencia
     *
     * Si el candado no llega dentro de `lock_timeout` (`55P03`) o PostgreSQL
     * rompe un abrazo con un tercero (`40P01`), esta jornada **no se ha
     * comparado**: se cuenta como fallo —quedo trabajo sin hacer, y el comando
     * sale en rojo— pero no como divergencia. Contarla como divergencia
     * convertiria un pico de fichajes en la alerta critica que significa
     * «alguien escribio la tabla por un camino que no es el recalculo».
     *
     * ## El residuo que se acepta
     *
     * **Una fila que todavia no existe no se puede bloquear.** Si la jornada es
     * de verdad una divergencia «fila ausente» y a la vez un fichaje de esa misma
     * persona y ese mismo dia esta confirmando en ese instante, el `INSERT …
     * ON CONFLICT` de esta correccion espera al suyo y termina pisandolo con lo
     * releido una fraccion de segundo antes. Es una ventana de milisegundos, pide
     * que coincidan dos sucesos que ya son raros por separado —la fila ausente no
     * deberia existir nunca— y **no toca el registro legal**: `shift_entries`
     * manda (regla dura 7) y la pasada siguiente lo vuelve a dejar bien. Cerrarla
     * exigiria un candado que el camino de fichaje tambien tomara, es decir,
     * encarecer los treinta fichajes por minuto de un cambio de turno para
     * proteger un caso que no deberia darse: no compensa.
     *
     * ## Que se publica
     *
     * Dos eventos, cada uno con su destinatario: `DailyTotalsRecalculated` lo
     * recoge el proyector —el unico camino de escritura de la tabla— y
     * `DailyTotalsReconciled` lo recoge `Compliance` para sellar el asiento de
     * `audit_log` con el valor anterior y el nuevo (regla dura 6). Si el asiento
     * falla, la reescritura revierte: es preferible una fila que sigue mintiendo
     * a una corregida de la que no queda constancia. Y se publican **los valores
     * releidos**, no los de la inspeccion: el asiento tiene que describir la
     * escritura que de verdad ocurrio.
     *
     * La transaccion la abre el puerto, no este metodo: un candado de
     * transaccion tomado sin transaccion se suelta al terminar la sentencia y no
     * serializa nada.
     */
    private function correct(DailyTotalsDivergence $suspicion, DateTimeImmutable $now): CorrectionAttempt
    {
        try {
            return $this->serialized->withChainLock(
                fn (): CorrectionAttempt => $this->recheckAndWrite($suspicion, $now),
            );
        } catch (Throwable $failure) {
            $sqlState = self::sqlStateOf($failure);

            $this->logger->error('attendance.projection_not_corrected', [
                'employee_uuid' => $suspicion->employeeUuid(),
                'work_date' => $suspicion->workDate(),
                'fields' => $suspicion->fields,
                'exception' => $failure::class,
                // El SQLSTATE distingue «no pude tomar el candado» de «la
                // escritura fallo»: sin el, las dos se leen igual en el log y la
                // primera parece un problema de integridad.
                'sqlstate' => $sqlState,
            ]);

            return self::isContention($sqlState)
                ? CorrectionAttempt::contended($suspicion)
                : CorrectionAttempt::failed($suspicion);
        }
    }

    /**
     * El SQLSTATE de un fallo del motor, o `null` si no viene de PostgreSQL.
     *
     * Se lee de `errorInfo` y no de `getCode()`: el codigo de una
     * `QueryException` es el SQLSTATE en unos drivers y un entero en otros, y
     * esta decision no puede depender de eso.
     */
    private static function sqlStateOf(Throwable $failure): ?string
    {
        if (! $failure instanceof QueryException) {
            return null;
        }

        $state = $failure->errorInfo[0] ?? null;

        return \is_string($state) ? $state : null;
    }

    /**
     * `55P03` lock_not_available (el `lock_timeout` de la instalacion) y `40P01`
     * deadlock_detected. Los dos dicen lo mismo: no se llego a comparar nada.
     */
    private static function isContention(?string $sqlState): bool
    {
        return $sqlState === '55P03' || $sqlState === '40P01';
    }

    /**
     * El cuerpo de la transaccion: bloquear, releer, comparar y solo entonces
     * escribir.
     */
    private function recheckAndWrite(DailyTotalsDivergence $suspicion, DateTimeImmutable $now): CorrectionAttempt
    {
        $employeeUuid = $suspicion->employeeUuid();
        $workDate = $suspicion->expected->workDate;

        $actual = $this->projection->lockedFor($employeeUuid, $workDate);
        $workDay = $this->workDays->workDayOf($employeeUuid, $workDate);

        if (! $workDay instanceof WorkDay && ! $actual instanceof ProjectedDailyTotal) {
            // Ni jornada ni fila: no hay nada que reconciliar. Ocurre cuando lo
            // que la inspeccion vio era una fila que otro proceso acabo de
            // retirar, y escribir aqui un dia a cero **inventaria** la fila que
            // la comparacion echa en falta.
            return $this->resolvedItself($suspicion, 'row_is_gone');
        }

        $divergence = DailyTotalsDivergence::between(
            $workDay instanceof WorkDay
                ? $this->expectedFor($workDay, $now)
                : $this->emptyFor($employeeUuid, $workDate, $now),
            $actual,
        );

        if (! $divergence instanceof DailyTotalsDivergence) {
            return $this->resolvedItself($suspicion, 'projection_caught_up');
        }

        $this->announce($divergence);

        $this->events->publish(
            $divergence->expected,
            $this->reconciled($divergence, $now),
        );

        return CorrectionAttempt::corrected($divergence);
    }

    /**
     * La sospecha que el candado no confirma.
     *
     * **Se deja escrita igualmente, y en `info`**: no es un incidente de
     * integridad —por eso no cuenta como divergencia ni sube la metrica— pero sin
     * esta linea no habria forma de distinguir «anoche no habia nada» de «anoche
     * hubo tres carreras con el turno de noche», que es justo lo que hay que
     * mirar si la proyeccion vuelve a aparecer torcida.
     */
    private function resolvedItself(DailyTotalsDivergence $suspicion, string $reason): CorrectionAttempt
    {
        $this->logger->notice('attendance.projection_divergence_resolved_itself', [
            'employee_uuid' => $suspicion->employeeUuid(),
            'work_date' => $suspicion->workDate(),
            'fields' => $suspicion->fields,
            'reason' => $reason,
        ]);

        return CorrectionAttempt::resolvedItself();
    }

    /**
     * Deja la diferencia en el log **antes** de escribir nada y **despues** de
     * confirmarla bajo candado.
     *
     * No es un adorno: en cuanto la fila se reescribe, la unica prueba de lo que
     * la proyeccion afirmaba esta aqui y en el asiento de auditoria. Sin este
     * apunte, a la mañana siguiente se sabria que hubo una divergencia y no cual
     * era.
     *
     * Que se anuncie con el candado tomado y no en la inspeccion es lo que hace
     * que esta linea siga significando algo: antes se escribia un `warning` por
     * cada sospecha, incluidas las que se deshacian solas al releer, y una
     * advertencia que aparece cada noche sin que haya nada roto se deja de leer.
     *
     * `employee_uuid`, nunca nombres (regla dura 21): esto viaja a Loki y de ahi
     * al paquete de diagnostico (ADR-020).
     */
    private function announce(DailyTotalsDivergence $divergence): void
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

    /**
     * El hecho que `Compliance` sella, con **los seis campos** a cada lado.
     *
     * Los seis y no el total y el numero de tramos: desde que la correccion no
     * escribe cuando la sospecha se deshace sola, este asiento es la unica copia
     * que queda de lo que la fila mala afirmaba, y una divergencia de
     * `has_open_shift` o de `last_out_at` no se puede reconstruir a partir de un
     * total (RL-04).
     */
    private function reconciled(DailyTotalsDivergence $divergence, DateTimeImmutable $now): DailyTotalsReconciled
    {
        return new DailyTotalsReconciled(
            employeeUuid: $divergence->employeeUuid(),
            workDate: $divergence->expected->workDate,
            divergentFields: $divergence->fields,
            before: $divergence->actual instanceof ProjectedDailyTotal
                ? new DailyTotalsSnapshot(
                    totalMinutes: $divergence->actual->totalMinutes,
                    shiftCount: $divergence->actual->shiftCount,
                    firstClockInAt: $divergence->actual->firstClockInAt,
                    lastClockOutAt: $divergence->actual->lastClockOutAt,
                    hasOpenShift: $divergence->actual->hasOpenShift,
                    hasIncident: $divergence->actual->hasIncident,
                )
                : null,
            after: new DailyTotalsSnapshot(
                totalMinutes: $divergence->expected->total->minutes,
                shiftCount: $divergence->expected->shiftCount,
                firstClockInAt: $divergence->expected->firstClockInAt,
                lastClockOutAt: $divergence->expected->lastClockOutAt,
                hasOpenShift: $divergence->expected->hasOpenShift,
                hasIncident: $divergence->expected->hasAnomaly,
            ),
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
     *
     * Los fallos van con el resto (tarea 3.2): `projection_reconciliation_last_failures`
     * es lo que distingue «anoche no habia nada que corregir» de «anoche hubo
     * algo y no se pudo corregir», que hasta ahora solo se podia leer en el
     * codigo de salida del comando.
     */
    private function publish(ReconciliationReport $report, DateTimeImmutable $now): ReconciliationReport
    {
        $this->metrics->reconciliationCompleted(
            $report->workDaysInspected,
            $report->divergences,
            $report->corrected,
            $report->failures,
            $report->selfResolved,
            $now,
        );

        $this->logger->notice('attendance.projection_reconciliation', [
            'from' => $report->fromIsoDate,
            'to' => $report->toIsoDate,
            'days_inspected' => $report->daysInspected,
            'work_days_inspected' => $report->workDaysInspected,
            'divergences' => $report->divergences,
            'corrected' => $report->corrected,
            'failures' => $report->failures,
            // Tambien va a `projection_reconciliation_last_self_resolved` (doc 02
            // §8.2): el log de la pasada nocturna no lo lee nadie —corre con
            // `runInBackground()`— asi que la huella de la carrera no puede
            // depender solo de esta linea.
            'self_resolved' => $report->selfResolved,
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
