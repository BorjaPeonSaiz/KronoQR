<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\CredentialPatternThresholds;
use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\PatternReviewState;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * **Patrones anomalos de uso de credencial** (RF-PR-06, RN-16, tarea 3.11).
 *
 * Es la contrapartida explicita de haber descartado la biometria (ADR-009,
 * doc 01 §3.8): el prestamo fisico de la tarjeta es el unico fraude que la
 * firma HMAC no impide, y sin una regla con umbral y bandeja esa mitigacion no
 * existiria en el producto.
 *
 * ## Lo que esta regla NO hace, y es la mitad de su valor
 *
 * **No concluye que haya fraude.** Aporta un indicio con sus numeros y lo pone
 * en la bandeja de una persona (RF-PR-06, doc 05 §4.6). **No anula, no marca y
 * no cierra ningun fichaje** (reglas duras 5 y 19): no tiene por donde: no
 * recibe tramos, solo escaneos, y lo unico que devuelve son hallazgos.
 *
 * ## Los tres patrones de RF-PR-06 son dos hallazgos
 *
 * | Patron del requisito | Hallazgo | Por que |
 * |---|---|---|
 * | «dos fichajes consecutivos en el mismo quiosco separados por segundos» | `kiosk_coincidence` | Es la **unidad de medida** |
 * | «coincidencias sistematicas entre dos empleados» | `kiosk_coincidence` | Es el **umbral** sobre esa unidad |
 * | «secuencias imposibles» | `impossible_sequence` | Es RN-16, enunciada aparte |
 *
 * ### `kiosk_coincidence`: **un hallazgo por PERSONA**, no por par
 *
 * Una **coincidencia** es un par de escaneos de dos personas distintas en el
 * mismo quiosco separados por menos de `windowSeconds`. Un par de personas es
 * «sistematico» cuando acumula al menos `minRepeats` **dias distintos** con
 * coincidencia en ese quiosco: por dia y no por escaneo porque la cola del
 * cambio de turno produce pares de segundos todos los dias entre companeros que
 * llegan juntos, y «sistematico» (RF-PR-06, doc 05 §12) es «varios dias», que es
 * lo que dice el Gherkin del doc 01 §11.
 *
 * **Cada persona con al menos un par sistematico recibe UNA incidencia**, no una
 * por par (decision 13a, bloqueante B2 de la revision). Antes se emitia una por
 * pareja y tres companeros que entran juntos producian seis hallazgos que la
 * restriccion `one_incident_per_finding` colapsaba en tres **en silencio**: la
 * mitad de los indicios desaparecia sin fila, sin asiento y sin log. Ahora el
 * contexto lleva la contraparte principal —la de mas dias; a igualdad, la de la
 * primera coincidencia— y `counterpart_count`, y el grupo entero se reconstruye
 * leyendo las incidencias de sus miembros, que es lo que el runbook explica.
 *
 * **`work_date` = el ultimo dia con coincidencia de la persona** (decision 13b,
 * bloqueante B1). Estuvo anclada al `minRepeats`-esimo dia y era inestable: con
 * la ventana de 30 dias saturada, ese dia avanza una noche tras otra y la misma
 * pareja estrenaba dos incidencias `high` cada madrugada.
 *
 * **`min_gap_seconds` se mide sobre TODAS las coincidencias** (R1), no sobre la
 * primera de cada dia: el hueco mas estrecho es el dato que hace dificil de
 * explicar el indicio, y era justo el que se tiraba.
 *
 * ### `impossible_sequence` (RN-16)
 *
 * Dos escaneos **aceptados** de la misma persona en **dispositivos distintos**
 * separados por menos de `minTransitSeconds`. Un solo caso basta: no es una
 * frecuencia, es una imposibilidad fisica. **No se evalua sobre una hora en duda
 * por el reloj** (RN-15, RF-AT-10): los escaneos con el desfase por encima de la
 * tolerancia se **quitan de la lista antes** de emparejar consecutivos, para que
 * uno dudoso en medio no anule a sus dos vecinos limpios (R4).
 *
 * ## Pura, y con el reloj por parametro (reglas duras 1 y 2)
 *
 * Ni reloj del sistema, ni configuracion, ni framework. El instante entra por
 * argumento, los cuatro umbrales llegan ya resueltos en
 * {@see CredentialPatternThresholds} y el estado de la bandeja en
 * {@see PatternReviewState}: el dominio no consulta la configuracion ni la
 * bandeja, las recibe.
 */
final readonly class CredentialPatternPolicy
{
    /** Valor de `context.pattern` para la coincidencia sistematica en el mismo quiosco. */
    public const string KIOSK_COINCIDENCE = 'kiosk_coincidence';

    /** Valor de `context.pattern` para la secuencia imposible de RN-16. */
    public const string IMPOSSIBLE_SEQUENCE = 'impossible_sequence';

    /**
     * El MISMO criterio de desfase que uso el quiosco al registrar el escaneo
     * (RN-15). Se construye del umbral que llega en los thresholds, no se copia
     * la comparacion: dos copias acabarian discrepando.
     */
    private ReviewPolicy $review;

    public function __construct(public CredentialPatternThresholds $thresholds)
    {
        $this->review = ReviewPolicy::toleratingSkewOfMinutes($thresholds->maximumClockSkewMinutes);
    }

    /**
     * Todos los hallazgos que sostienen los escaneos recibidos.
     *
     * @param  list<CredentialScan>  $scans  Los usos de credencial de la ventana revisada, en cualquier orden.
     * @param  array<string, PatternReviewState>  $reviewed  Que se ha hecho ya con los indicios de cada persona.
     * @return list<DetectedAnomaly>
     */
    public function inspect(array $scans, int $siteId, DateTimeImmutable $detectedAt, array $reviewed = []): array
    {
        return [
            ...$this->coincidences($scans, $siteId, $detectedAt, $reviewed),
            ...$this->impossibleSequences($scans, $siteId, $detectedAt),
        ];
    }

    /**
     * RF-PR-06: las personas que coinciden sistematicamente con alguien en el
     * mismo quiosco.
     *
     * @param  list<CredentialScan>  $scans
     * @param  array<string, PatternReviewState>  $reviewed
     * @return list<DetectedAnomaly>
     */
    private function coincidences(array $scans, int $siteId, DateTimeImmutable $detectedAt, array $reviewed): array
    {
        if ($this->thresholds->coincidenceIsDisabled()) {
            return [];
        }

        $anomalies = [];

        foreach ($this->byPersonAndCounterpart($scans) as $employeeUuid => $groups) {
            $state = $reviewed[$employeeUuid] ?? PatternReviewState::untouched();

            // El indicio ya esta sobre la mesa de alguien: repetirlo no aporta un
            // dato, aporta una fila. Las contrapartes nuevas entraran en la
            // pasada siguiente a que alguien lo trabaje.
            if ($state->hasOpen) {
                continue;
            }

            $finding = $this->coincidenceFinding($employeeUuid, $groups, $state, $siteId, $detectedAt);

            if ($finding instanceof DetectedAnomaly) {
                $anomalies[] = $finding;
            }
        }

        return $anomalies;
    }

    /**
     * Las coincidencias de cada persona, agrupadas por contraparte **y quiosco**.
     *
     * El quiosco entra en el grupo porque lo que RF-PR-06 describe es una
     * coincidencia sistematica **en el mismo quiosco**: dos personas que se
     * cruzan un dia en recepcion y otro en cocina no estan compartiendo una cola.
     *
     * Se guardan **todas** las coincidencias y no una por dia (R1): el recuento
     * sigue siendo de dias distintos, pero el hueco mas estrecho —el dato que
     * sostiene el indicio— puede estar en la segunda del dia.
     *
     * @param  list<CredentialScan>  $scans
     * @return array<string, array<string, array{counterpart: string, deviceId: int, deviceName: string, events: list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>}>>
     */
    private function byPersonAndCounterpart(array $scans): array
    {
        $ordered = $this->orderedByInstant($scans);
        $total = \count($ordered);
        $byPerson = [];

        for ($i = 0; $i < $total; $i++) {
            $earlier = $ordered[$i];

            for ($j = $i + 1; $j < $total; $j++) {
                $later = $ordered[$j];
                $gap = $earlier->gapSecondsTo($later);

                // Ordenados por instante: en cuanto uno se sale de la ventana,
                // los siguientes tambien. Es lo que impide que esto sea
                // cuadratico sobre un mes de fichajes.
                if (! $this->thresholds->isCoincidence($gap)) {
                    break;
                }

                if ($earlier->isSamePersonAs($later) || ! $earlier->isSameDeviceAs($later)) {
                    continue;
                }

                $event = ['at' => $earlier->occurredAt, 'gap' => $gap, 'workDate' => $earlier->workDate];

                // La MISMA coincidencia entra en la cuenta de las dos personas:
                // cada una tiene su propia incidencia, asignada al responsable de
                // su departamento (decision 2).
                $byPerson[$earlier->employeeUuid][$this->groupKey($later, $earlier)] ??= [
                    'counterpart' => $later->employeeUuid,
                    'deviceId' => $earlier->deviceId,
                    'deviceName' => $earlier->deviceName,
                    'events' => [],
                ];
                $byPerson[$earlier->employeeUuid][$this->groupKey($later, $earlier)]['events'][] = $event;

                $byPerson[$later->employeeUuid][$this->groupKey($earlier, $later)] ??= [
                    'counterpart' => $earlier->employeeUuid,
                    'deviceId' => $earlier->deviceId,
                    'deviceName' => $earlier->deviceName,
                    'events' => [],
                ];
                $byPerson[$later->employeeUuid][$this->groupKey($earlier, $later)]['events'][] = $event;
            }
        }

        ksort($byPerson);

        return $byPerson;
    }

    /**
     * El hallazgo de una persona, o `null` si ninguna de sus contrapartes llega
     * al umbral.
     *
     * @param  array<string, array{counterpart: string, deviceId: int, deviceName: string, events: list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>}>  $groups
     */
    private function coincidenceFinding(
        string $employeeUuid,
        array $groups,
        PatternReviewState $state,
        int $siteId,
        DateTimeImmutable $detectedAt,
    ): ?DetectedAnomaly {
        /** @var list<array{counterpart: string, deviceId: int, deviceName: string, days: int, events: list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>}> $systematic */
        $systematic = [];

        foreach ($groups as $group) {
            // Solo lo posterior al ultimo cierre: quien descarto «llegan juntos
            // en coche» no vuelve a ver la misma incidencia a la noche siguiente,
            // y hacen falta `minRepeats` dias NUEVOS para reabrir.
            $events = array_values(array_filter(
                $group['events'],
                static fn (array $event): bool => $state->counts($event['at']),
            ));

            $days = $this->distinctDays($events);

            if (! $this->thresholds->isSystematic(\count($days))) {
                continue;
            }

            $systematic[] = [
                'counterpart' => $group['counterpart'],
                'deviceId' => $group['deviceId'],
                'deviceName' => $group['deviceName'],
                'days' => \count($days),
                'events' => $events,
            ];
        }

        if ($systematic === []) {
            return null;
        }

        $principal = $this->principalCounterpart($systematic);

        /** @var list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}> $events */
        $events = array_merge(...array_column($systematic, 'events'));
        usort($events, static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        $first = $events[0];
        $last = $events[\count($events) - 1];

        return new DetectedAnomaly(
            type: AnomalyType::ANOMALOUS_PATTERN,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            // El ULTIMO dia con coincidencia, que no depende del borde de la
            // ventana: mientras el habito no cambie, la jornada del hallazgo no
            // avanza sola y `one_incident_per_finding` absorbe la repeticion.
            workDate: $last['workDate'],
            // Ningun tramo lo explica: lo que se observo es una serie de
            // escaneos a lo largo de varios dias, no una jornada concreta.
            shiftEntryUuid: null,
            detectedAt: $detectedAt,
            context: [
                'pattern' => self::KIOSK_COINCIDENCE,
                'device_id' => $principal['deviceId'],
                'device_name' => $principal['deviceName'],
                // La contraparte viaja como UUID y nunca como nombre: lo resuelve el panel
                // con su directorio y nunca entra en el contexto (regla dura 21).
                'counterpart_employee_uuid' => $principal['counterpart'],
                'counterpart_count' => \count($systematic),
                'coincidence_days' => $principal['days'],
                'window_seconds' => $this->thresholds->windowSeconds,
                'min_repeats' => $this->thresholds->minRepeats,
                'first_coincidence_at' => UtcInstant::of($first['at']),
                'last_coincidence_at' => UtcInstant::of($last['at']),
                'last_gap_seconds' => $last['gap'],
                'min_gap_seconds' => $this->narrowestGap($events),
            ],
        );
    }

    /**
     * La contraparte que mejor describe el indicio: la de mas dias y, a
     * igualdad, la de la primera coincidencia.
     *
     * El desempate no es un adorno: sin el, dos contrapartes con los mismos dias
     * podrian alternarse entre dos pasadas identicas, y el contexto de la
     * incidencia senalaria a una persona distinta cada noche.
     *
     * @param  list<array{counterpart: string, deviceId: int, deviceName: string, days: int, events: list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>}>  $systematic
     * @return array{counterpart: string, deviceId: int, deviceName: string, days: int, events: list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>}
     */
    private function principalCounterpart(array $systematic): array
    {
        $principal = $systematic[0];

        foreach ($systematic as $candidate) {
            if ($candidate['days'] > $principal['days']) {
                $principal = $candidate;

                continue;
            }

            if ($candidate['days'] === $principal['days']
                && $candidate['events'][0]['at']->getTimestamp() < $principal['events'][0]['at']->getTimestamp()) {
                $principal = $candidate;
            }
        }

        return $principal;
    }

    /**
     * Los dias civiles distintos en los que hubo coincidencia.
     *
     * @param  list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>  $events
     * @return list<string>
     */
    private function distinctDays(array $events): array
    {
        $days = [];

        foreach ($events as $event) {
            $days[$event['workDate']->isoDate] = true;
        }

        return array_keys($days);
    }

    /**
     * El hueco mas estrecho de todas las coincidencias (R1).
     *
     * @param  list<array{at: DateTimeImmutable, gap: int, workDate: WorkDate}>  $events
     */
    private function narrowestGap(array $events): int
    {
        $narrowest = $events[0]['gap'];

        foreach ($events as $event) {
            $narrowest = min($narrowest, $event['gap']);
        }

        return $narrowest;
    }

    /**
     * RN-16: las secuencias que ningun transito explica.
     *
     * **Una por persona y jornada**, como `out_of_order_scan` y por el mismo
     * motivo: varias imposibilidades el mismo dia dicen lo mismo —«el uso de
     * esta credencial ese dia no cuadra»— y multiplicarlas solo infla el
     * contador sin anadir nada que revisar. Se queda la mas antigua, que es la
     * que empieza a explicar el dia.
     *
     * @param  list<CredentialScan>  $scans
     * @return list<DetectedAnomaly>
     */
    private function impossibleSequences(array $scans, int $siteId, DateTimeImmutable $detectedAt): array
    {
        if ($this->thresholds->impossibleSequenceIsDisabled()) {
            return [];
        }

        /** @var array<string, DetectedAnomaly> $byEmployeeAndDay */
        $byEmployeeAndDay = [];

        foreach ($this->byEmployee($this->withoutDoubtfulHours($scans)) as $ownScans) {
            $total = \count($ownScans);

            for ($i = 1; $i < $total; $i++) {
                $first = $ownScans[$i - 1];
                $second = $ownScans[$i];

                if (! $this->isImpossible($first, $second)) {
                    continue;
                }

                $key = $second->employeeUuid.'|'.$second->workDate->isoDate;
                $byEmployeeAndDay[$key] ??= $this->impossibleFinding($first, $second, $siteId, $detectedAt);
            }
        }

        return array_values($byEmployeeAndDay);
    }

    /**
     * Los escaneos cuya hora NO esta en duda por el reloj (RN-16, RN-15).
     *
     * **Se filtra antes de emparejar y no al comparar el par** (R4): con el
     * descarte por par, un escaneo dudoso en medio de dos limpios anulaba a los
     * dos vecinos, que es exactamente al reves de lo que RN-16 quiere. Quitarlo
     * de la lista deja que sus vecinos se comparen entre si.
     *
     * @param  list<CredentialScan>  $scans
     * @return list<CredentialScan>
     */
    private function withoutDoubtfulHours(array $scans): array
    {
        return array_values(array_filter($scans, function (CredentialScan $scan): bool {
            $skew = $scan->clockSkew();

            return $skew === null || ! $this->review->exceedsSkewTolerance($skew);
        }));
    }

    /**
     * RN-16 sobre dos escaneos consecutivos de la misma persona: dispositivos
     * distintos y hueco por debajo del transito minimo.
     *
     * La hora en duda ya no se mira aqui: esos escaneos no han llegado
     * ({@see withoutDoubtfulHours()}).
     */
    private function isImpossible(CredentialScan $first, CredentialScan $second): bool
    {
        if ($first->isSameDeviceAs($second)) {
            return false;
        }

        return $this->thresholds->isImpossibleTransit($first->gapSecondsTo($second));
    }

    private function impossibleFinding(
        CredentialScan $first,
        CredentialScan $second,
        int $siteId,
        DateTimeImmutable $detectedAt,
    ): DetectedAnomaly {
        return new DetectedAnomaly(
            type: AnomalyType::ANOMALOUS_PATTERN,
            employeeUuid: $second->employeeUuid,
            siteId: $siteId,
            // La jornada del segundo escaneo: es el que no podia haber ocurrido.
            workDate: $second->workDate,
            // El tramo del segundo escaneo y, si no produjo ninguno —un
            // `rejected_debounce`, decision 15— el del primero (decision 14).
            // Nulo solo cuando ninguno de los dos tiene, y entonces la colision
            // con una `kiosk_coincidence` del mismo dia la detecta y la cuenta
            // el libro de incidencias.
            shiftEntryUuid: $second->shiftEntryUuid ?? $first->shiftEntryUuid,
            detectedAt: $detectedAt,
            context: [
                'pattern' => self::IMPOSSIBLE_SEQUENCE,
                'from_device_id' => $first->deviceId,
                'from_device_name' => $first->deviceName,
                'to_device_id' => $second->deviceId,
                'to_device_name' => $second->deviceName,
                'first_occurred_at' => UtcInstant::of($first->occurredAt),
                'second_occurred_at' => UtcInstant::of($second->occurredAt),
                'gap_seconds' => $first->gapSecondsTo($second),
                'transit_seconds' => $this->thresholds->minTransitSeconds,
                'first_scan_id' => $first->scanId,
                'second_scan_id' => $second->scanId,
            ],
        );
    }

    /**
     * Los escaneos ordenados por instante, y con un desempate estable.
     *
     * El desempate por `scan_id` existe para que dos escaneos con el mismo
     * `occurred_at` —posible: dos tablets, o una cola que drena— produzcan
     * siempre el mismo hallazgo. Sin el, el `work_date` de la incidencia podria
     * cambiar entre dos ejecuciones identicas y `one_incident_per_finding`
     * dejaria de absorberla.
     *
     * @param  list<CredentialScan>  $scans
     * @return list<CredentialScan>
     */
    private function orderedByInstant(array $scans): array
    {
        usort($scans, static fn (CredentialScan $a, CredentialScan $b): int => [$a->occurredAt->getTimestamp(), $a->scanId]
            <=> [$b->occurredAt->getTimestamp(), $b->scanId]);

        return $scans;
    }

    /**
     * Los escaneos de cada persona, en orden.
     *
     * @param  list<CredentialScan>  $scans
     * @return array<string, list<CredentialScan>>
     */
    private function byEmployee(array $scans): array
    {
        $byEmployee = [];

        foreach ($this->orderedByInstant($scans) as $scan) {
            $byEmployee[$scan->employeeUuid][] = $scan;
        }

        ksort($byEmployee);

        return $byEmployee;
    }

    /** Clave del grupo de una persona: con quien coincidio y en que quiosco. */
    private function groupKey(CredentialScan $counterpart, CredentialScan $at): string
    {
        return $at->deviceId.'|'.$counterpart->employeeUuid;
    }
}
