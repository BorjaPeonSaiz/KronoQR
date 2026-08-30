<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use DateTimeImmutable;

/**
 * Que situaciones del registro horario piden que las mire una persona
 * (RF-PR-01, tarea 2.6).
 *
 * Reune las seis reglas que la revision diaria evalua, y las reune a proposito:
 * quien lea esta clase tiene que poder ver de una vez todo lo que puede acabar
 * en la bandeja de un responsable, porque el criterio de «que merece
 * interrumpir a alguien» solo se puede juzgar en conjunto.
 *
 * | Regla | Que mira | De donde sale su umbral |
 * |---|---|---|
 * | RN-07 | Tramo cerrado por debajo del minimo computable | {@see ClockingPolicy}, operativo |
 * | RN-08 | Tramo abierto o cerrado por encima del maximo | {@see ClockingPolicy}, operativo |
 * | RN-10 | Descanso entre el fin de una jornada y el inicio de la siguiente | {@see CompliancePolicy}, legal |
 * | RN-11 | Suma de los tramos vigentes de la jornada | {@see CompliancePolicy}, legal |
 * | RN-12 | Tramo continuo sin pausa registrada | {@see CompliancePolicy}, legal |
 * | RN-15 | Desfase de reloj del escaneo | {@see ReviewPolicy}, operativo |
 *
 * **Los seis umbrales llegan resueltos** (regla dura 14). Aqui no hay ni una
 * constante ni una llamada a la configuracion: los operativos los fija el hotel
 * en `installation_settings` y los legales la jurisdiccion en
 * `compliance_profiles`, y el caso de uso los pide a sus puertos y construye
 * esta politica. Es lo que permite vender a un cliente con otro convenio
 * cambiando una fila (ADR-017).
 *
 * **Y el instante «ahora» se recibe, no se pregunta** (regla dura 2). Sin eso no
 * se puede probar el turno abierto de doce horas y un minuto.
 *
 * **Ninguna regla cierra, corrige ni descarta nada.** RN-08 lo dice literal:
 * «nunca se cierra automaticamente sin intervencion humana». Esta politica
 * clasifica; `Compliance` abre la incidencia y una persona decide (regla dura
 * 19).
 */
final readonly class AnomalyDetectionPolicy
{
    /** RN-10: descanso minimo entre el fin de un turno y el inicio del siguiente. */
    public WorkedDuration $minimumRest;

    /** RN-11: jornada diaria ordinaria por encima de la cual se alerta. */
    public WorkedDuration $maximumDaily;

    /** RN-12: tramo continuo maximo sin pausa registrada. */
    public WorkedDuration $breakRequiredAfter;

    /**
     * Los umbrales legales llegan en minutos dentro de {@see CompliancePolicy}
     * —Shared no puede depender de un modulo (doc 02 §1.6)— y se convierten a
     * la unidad del dominio **aqui, una sola vez**, en el borde. Que cada regla
     * lo hiciera por su cuenta es como acaban dos comparaciones midiendo cosas
     * distintas.
     */
    public function __construct(
        public ClockingPolicy $clocking,
        public ReviewPolicy $review,
        CompliancePolicy $legal,
    ) {
        $this->minimumRest = WorkedDuration::ofMinutes($legal->minimumRestMinutes);
        $this->maximumDaily = WorkedDuration::ofMinutes($legal->maximumDailyMinutes);
        $this->breakRequiredAfter = WorkedDuration::ofMinutes($legal->breakRequiredAfterMinutes);
    }

    /**
     * Todo lo que hay que revisar de esta jornada, en el orden en que ocurrio:
     * primero lo de cada tramo, luego lo del dia entero, y por ultimo el hueco
     * con la jornada anterior.
     *
     * `$previousShiftEndedAt` es el fin del ultimo tramo **anterior** a esta
     * jornada, o `null` si no consta. Cuando no consta **no se evalua RN-10**:
     * suponer que el dia anterior termino a medianoche produciria una alerta
     * sobre alguien que acaba de incorporarse.
     *
     * @return list<DetectedAnomaly>
     */
    public function inspect(WorkDay $day, DateTimeImmutable $now, ?DateTimeImmutable $previousShiftEndedAt = null): array
    {
        TimeRange::assertUtc('now', $now);

        $anomalies = [];
        $entryExplainsTheDay = false;

        foreach ($day->entries() as $entry) {
            foreach ($this->inspectEntry($day, $entry, $now) as $anomaly) {
                $anomalies[] = $anomaly;

                $entryExplainsTheDay = $entryExplainsTheDay || $anomaly->type === AnomalyType::LONG_SHIFT;
            }
        }

        $dailyExcess = $this->dailyExcess($day, $now, $entryExplainsTheDay);

        if ($dailyExcess instanceof DetectedAnomaly) {
            $anomalies[] = $dailyExcess;
        }

        $rest = $this->insufficientRest($day, $now, $previousShiftEndedAt);

        if ($rest instanceof DetectedAnomaly) {
            $anomalies[] = $rest;
        }

        return $anomalies;
    }

    /**
     * RN-15: el desfase de reloj que **requiere validacion del responsable**.
     *
     * Delega en {@see ReviewPolicy}, que es quien ya hace esta misma
     * comparacion al registrar el escaneo. Repetirla aqui con un `>` propio
     * seria la forma mas silenciosa de que el quiosco marcara un fichaje que la
     * revision diaria no considera desviado, o al reves.
     *
     * **A diferencia de `ReviewPolicy::requiresReview()`, el origen no cuenta.**
     * Aquella pregunta «¿me fio de esta marca tal como llego?», y un fichaje por
     * PIN pide revision aunque el reloj sea perfecto (RF-AT-11). Esta pregunta
     * otra cosa: si el RELOJ estaba desviado, que es lo unico que la incidencia
     * `clock_skew` afirma.
     */
    public function skewRequiresValidation(ClockSkew $skew): bool
    {
        return $this->review->exceedsSkewTolerance($skew);
    }

    /**
     * Lo que hay que revisar de un tramo suelto.
     *
     * @return list<DetectedAnomaly>
     */
    private function inspectEntry(WorkDay $day, ShiftEntry $entry, DateTimeImmutable $now): array
    {
        if ($entry->isOpen()) {
            return $this->inspectOpenEntry($day, $entry, $now);
        }

        $worked = $entry->workedDuration();
        $anomalies = [];

        if ($this->clocking->isTooShort($worked)) {
            $anomalies[] = $this->anomaly(AnomalyType::SHORT_SHIFT, $day, $entry->uuid(), $now, [
                'worked_minutes' => $worked->minutes,
                'threshold_minutes' => $this->clocking->minimumComputableShift->minutes,
            ]);
        }

        if ($this->clocking->isTooLong($worked)) {
            $anomalies[] = $this->anomaly(AnomalyType::LONG_SHIFT, $day, $entry->uuid(), $now, [
                'worked_minutes' => $worked->minutes,
                'threshold_minutes' => $this->clocking->anomalousShiftThreshold->minutes,
            ]);
        }

        // RN-12. Solo sobre tramos CERRADOS: mientras uno sigue vivo, «lleva 6 h
        // y media» no es un hecho —la persona puede estar a punto de salir a
        // comer— y alertarlo convertiria cada turno largo en dos incidencias.
        if ($worked->isLongerThan($this->breakRequiredAfter)) {
            $anomalies[] = $this->anomaly(AnomalyType::MISSING_BREAK, $day, $entry->uuid(), $now, [
                'worked_minutes' => $worked->minutes,
                'threshold_minutes' => $this->breakRequiredAfter->minutes,
            ]);
        }

        return $anomalies;
    }

    /**
     * RN-08 sobre un tramo que sigue abierto: el escenario «Turno olvidado».
     *
     * Se mide contra `$now` y no contra una hora de salida que no existe. Si el
     * reloj fuera anterior a la entrada —una marca retrodatada llegada de la
     * cola offline (regla dura 9)— no hay nada que medir y no se inventa una
     * duracion negativa.
     *
     * @return list<DetectedAnomaly>
     */
    private function inspectOpenEntry(WorkDay $day, ShiftEntry $entry, DateTimeImmutable $now): array
    {
        if ($now->getTimestamp() <= $entry->clockedInAt()->getTimestamp()) {
            return [];
        }

        $elapsed = (new TimeRange($entry->clockedInAt(), $now))->duration();

        if (! $this->clocking->isTooLong($elapsed)) {
            return [];
        }

        return [$this->anomaly(AnomalyType::OPEN_SHIFT_EXPIRED, $day, $entry->uuid(), $now, [
            'open_minutes' => $elapsed->minutes,
            'threshold_minutes' => $this->clocking->anomalousShiftThreshold->minutes,
        ])];
    }

    /**
     * RN-11 sobre la **suma de los tramos vigentes y cerrados** de la jornada,
     * que es lo que la distingue de RN-08: aquella mira un tramo, esta mira el
     * dia.
     *
     * **Cerrados, y esto hay que saberlo para leer la bandeja.** `totalWorked()`
     * cuenta cero por un tramo abierto —el registro legal suma lo fichado, no lo
     * que va corriendo—, asi que una jornada de cuatro horas cerradas mas nueve
     * abiertas **no alerta hoy**: alertara la noche siguiente al cierre, cuando el
     * dia sea un hecho. Mientras tanto, lo que ese tramo abierto tiene de raro lo
     * dice RN-08, con su propio umbral y su propia incidencia. Es el mismo
     * criterio que en RN-12: mientras un tramo sigue vivo, su duracion todavia no
     * es una afirmacion sobre nada.
     *
     * **No se cuelga de ningun tramo** (`shiftEntryUuid` a nulo): ninguno por si
     * solo explica el exceso, y señalar uno arbitrario haria pensar que ese es
     * el problema.
     *
     * **Y no se emite si un tramo ya lo explica.** Un tramo de trece horas
     * dispara RN-08 —mas preciso, y ademas señala cual— y superaria tambien
     * RN-11. Dos incidencias que dicen lo mismo con distinta precision solo
     * hacen ruido en la bandeja de quien las revisa, y el ruido es lo que hace
     * que se dejen de mirar.
     */
    private function dailyExcess(WorkDay $day, DateTimeImmutable $now, bool $entryExplainsTheDay): ?DetectedAnomaly
    {
        if ($entryExplainsTheDay) {
            return null;
        }

        $worked = $day->totalWorked();

        if (! $worked->isLongerThan($this->maximumDaily)) {
            return null;
        }

        return $this->anomaly(AnomalyType::LONG_SHIFT, $day, null, $now, [
            'worked_minutes' => $worked->minutes,
            'threshold_minutes' => $this->maximumDaily->minutes,
        ]);
    }

    /**
     * RN-10: el descanso entre el fin de la jornada anterior y el inicio de
     * esta.
     *
     * Se cuelga del tramo que **abre** la jornada, que es el que empezo antes de
     * tiempo y el que quien revisa necesita mirar.
     *
     * El umbral es estricto por abajo, como el resto: con 12 h, 11 h 59 alerta y
     * 12 h exactas no.
     *
     * ## Lo que esta regla NO mira todavia, y por que
     *
     * Solo compara **entre jornadas**: la ultima salida anterior contra la
     * primera entrada de esta. El hueco entre dos tramos de la **misma** jornada
     * no se evalua, y con RN-05 eso incluye un caso real —salir a las 15:00 y
     * volver a entrar a las 23:00 el mismo dia son dos tramos de la misma
     * `work_date`, con ocho horas de por medio, y aqui no salta nada; si esa
     * segunda entrada cayera a las 00:30 seria otra jornada y si saltaria—.
     *
     * **No es un descuido: es que hoy no se puede distinguir.** Sin la intencion
     * declarada del fichaje (ADR-024, RF-AT-12, tarea 3.5), un hueco entre dos
     * tramos puede ser una pausa para comer o el descanso entre dos turnos, y las
     * dos cosas se leen igual en la tabla. Alertar de todas convertiria cada
     * jornada partida —el turno tipico de hosteleria— en un incumplimiento del
     * art. 34.3 ET, y eso es peor que no alertar: una bandeja llena de falsos
     * positivos es una bandeja que se deja de mirar. Cuando exista la pausa
     * declarada, aqui se evaluara tambien el hueco intrajornada que no sea pausa.
     */
    private function insufficientRest(WorkDay $day, DateTimeImmutable $now, ?DateTimeImmutable $previousShiftEndedAt): ?DetectedAnomaly
    {
        if (! $previousShiftEndedAt instanceof DateTimeImmutable) {
            return null;
        }

        $openingEntry = $this->openingEntryOf($day);

        if (! $openingEntry instanceof ShiftEntry) {
            return null;
        }

        $firstClockInAt = $openingEntry->clockedInAt();

        // Una jornada que empieza ANTES de que termine la anterior no describe un
        // descanso corto: describe un solape, y de eso responde RN-02 en el
        // esquema. Medirlo aqui daria un descanso negativo, que ademas se leeria
        // como el incumplimiento mas grave posible.
        //
        // Empezar EXACTAMENTE cuando termino la anterior si es descanso: cero
        // minutos, que es el peor cumplimiento de RN-10 que se puede registrar
        // sin solapar. Por eso la resta se hace sobre las marcas y no con un
        // `TimeRange`, que exige fin > inicio (RN-03) y no admite el cero.
        $restSeconds = $firstClockInAt->getTimestamp() - $previousShiftEndedAt->getTimestamp();

        if ($restSeconds < 0) {
            return null;
        }

        $rest = WorkedDuration::ofMinutes(intdiv($restSeconds, 60));

        if (! $rest->isShorterThan($this->minimumRest)) {
            return null;
        }

        return $this->anomaly(AnomalyType::INSUFFICIENT_REST, $day, $openingEntry->uuid(), $now, [
            'rest_minutes' => $rest->minutes,
            'threshold_minutes' => $this->minimumRest->minutes,
        ]);
    }

    /**
     * El tramo que **abre** la jornada: el de entrada mas temprana.
     *
     * Se busca y no se toma `entries()[0]`. El agregado conserva el orden en que
     * el repositorio los cargo —hoy por `clocked_in_at`— y `reconstitute()` no
     * ordena nada: colgar la incidencia de la posicion cero la ataria a una
     * garantia que el dominio no da, y bastaria con que otro consumidor cargara
     * los tramos por `id` para que RN-10 señalara el tramo equivocado.
     */
    private function openingEntryOf(WorkDay $day): ?ShiftEntry
    {
        $opening = null;

        foreach ($day->entries() as $entry) {
            if (! $opening instanceof ShiftEntry || $entry->clockedInAt() < $opening->clockedInAt()) {
                $opening = $entry;
            }
        }

        return $opening;
    }

    /**
     * @param  array<string, int>  $context
     */
    private function anomaly(
        AnomalyType $type,
        WorkDay $day,
        ?string $shiftEntryUuid,
        DateTimeImmutable $detectedAt,
        array $context,
    ): DetectedAnomaly {
        return new DetectedAnomaly(
            type: $type,
            employeeUuid: $day->employeeUuid(),
            siteId: $day->siteId(),
            workDate: $day->workDate(),
            shiftEntryUuid: $shiftEntryUuid,
            detectedAt: $detectedAt,
            context: $context,
        );
    }
}
