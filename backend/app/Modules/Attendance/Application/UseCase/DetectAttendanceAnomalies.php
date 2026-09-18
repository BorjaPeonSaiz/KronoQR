<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\DetectAnomaliesCommand;
use App\Modules\Attendance\Application\Port\AnomalyMetrics;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\FlaggedScan;
use App\Modules\Attendance\Application\Port\FlaggedScans;
use App\Modules\Attendance\Application\Port\IncidentDetectionMetrics;
use App\Modules\Attendance\Application\Port\OutOfOrderScans;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Application\Support\ClockingPolicies;
use App\Modules\Attendance\Domain\Event\AttendanceAnomalyDetected;
use App\Modules\Attendance\Domain\Event\AttendanceReviewCompleted;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\Policy\AnomalyDetectionPolicy;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La revision diaria del registro horario (RF-PR-01, tarea 2.6).
 *
 * Recorre lo que hay que mirar, pregunta al dominio que tiene de raro y **emite
 * un hallazgo por cada cosa que encuentra**. No abre incidencias, no envia
 * avisos y, sobre todo, **no toca ni un tramo**: RN-08 dice literal que un turno
 * abierto «nunca se cierra automaticamente sin intervencion humana», y la unica
 * forma de garantizarlo es que este caso de uso no tenga por donde escribir —sus
 * dos puertos de lectura no exponen ningun `save()`—.
 *
 * ## Que revisa, y hasta donde hacia atras
 *
 * | Que | Ventana | Por que |
 * |---|---|---|
 * | Tramos todavia abiertos | **Ninguna** | Un turno sin cerrar no es historia: sigue creciendo (RN-08) |
 * | Jornadas con tramos cerrados | `lookbackDays` | Decision de retroactividad, doc 01 §4 |
 * | Escaneos marcados para revision | `lookbackDays` | RN-15, leyendo hacia atras `flagged_for_review` |
 * | Fichajes irreconciliables | `lookbackDays` | RN-18, leyendo hacia atras `result = 'rejected_out_of_order'` |
 *
 * ## Los umbrales llegan por sus puertos, y son de dos clases
 *
 * Los **operativos** —RN-07, RN-08 y la tolerancia de desfase— los fija el hotel
 * y los sirve `OperationalSettingsProvider`. Los **legales** —RN-10, RN-11 y
 * RN-12— los fija la jurisdiccion y los sirve `CompliancePolicyProvider`. Ni uno
 * solo esta escrito aqui (regla dura 14, ADR-017).
 *
 * ## Una instalacion, un centro
 *
 * Se resuelve el centro con `InstallationSiteProvider` porque el producto es de
 * un solo centro (migracion `enforce_single_site`) y porque sin su zona horaria
 * no se puede decir a que jornada pertenece un turno de noche (RN-05). Sin centro
 * —instalacion recien instalada, RF-PD-03— la pasada no hace nada y **no falla**.
 */
final readonly class DetectAttendanceAnomalies
{
    public function __construct(
        private WorkDayLedger $workDays,
        private FlaggedScans $flaggedScans,
        /** RN-18: los escaneos que el fichaje ya registro como irreconciliables. */
        private OutOfOrderScans $outOfOrderScans,
        private InstallationSiteProvider $sites,
        private OperationalSettingsProvider $settings,
        private CompliancePolicyProvider $compliance,
        private EventPublisher $events,
        private AnomalyMetrics $anomalyMetrics,
        private IncidentDetectionMetrics $detectionMetrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(DetectAnomaliesCommand $command): AnomalyScanResult
    {
        $site = $this->sites->installationSite();

        $now = $this->clock->now();

        if ($site === null) {
            // Con ceros, pero se publica (tarea 3.2). Una serie que solo aparece
            // cuando hay centro es indistinguible de un planificador parado, y
            // `DeteccionDeIncidenciasAusente` no podria separar los dos casos.
            return $this->measure(AnomalyScanResult::withoutSite(), $now);
        }

        $timezone = new DateTimeZone($site->timezone);
        $policy = $this->policyFor($site->id);

        // RF-AT-12 y decision 8 de la ficha 3.5: que reglas abren incidencia en
        // ESTA instalacion depende de si el hotel ficha la pausa. Se resuelve
        // aqui —que es quien alcanza los ajustes— y se le pasa ya resuelta al
        // dominio (regla dura 14). Una pasada entera usa el mismo valor: leerlo
        // por hallazgo podria dar dos respuestas si alguien cambia el ajuste a
        // mitad de la noche.
        $suspension = ComplianceRuleSuspension::forInstallation(
            $this->settings->forSite($site->id)->breakClockingEnabled,
        );

        $workDays = $this->workDaysToInspect($command, $timezone, $now);

        $anomalies = [
            ...$this->inspectWorkDays($workDays, $policy, $now, $suspension),
            ...$this->inspectFlaggedScans($command, $policy, $site->id, $timezone, $now),
            ...$this->inspectOutOfOrderScans($command, $site->id, $timezone, $now),
        ];

        $failures = $this->publishEach($anomalies);

        // El aviso al responsable es **un resumen por ejecucion** y no un correo
        // por hallazgo (RF-PR-01): quien lo compone es `Compliance`, que es quien
        // tiene las incidencias y sus responsables. Este evento solo dice que la
        // pasada termino.
        //
        // **Se publica tambien cuando algun hallazgo fallo**, y a proposito: lo
        // que se abrio hay que avisarlo. Callar el resumen por un fallo de otro
        // hallazgo dejaria sin aviso a responsables cuyas incidencias si estan
        // escritas.
        $this->events->publish(new AttendanceReviewCompleted(
            siteId: $site->id,
            completedAt: $now,
            daysInspected: $command->lookbackDays,
            anomaliesDetected: \count($anomalies),
        ));

        $byType = $this->tally($anomalies);

        // `anomalous_patterns_detected_total{pattern}` (doc 02 §8.2, tarea 3.1).
        // El MISMO recuento que devuelve el comando, para que la serie de
        // Grafana y la salida de `attendance:detect-incidents` no puedan
        // discrepar. Va detras del aviso a proposito: medir es lo ultimo y no
        // puede impedir nada de lo anterior.
        $this->anomalyMetrics->anomaliesDetected($byType);

        return $this->measure(
            AnomalyScanResult::of(
                daysInspected: $command->lookbackDays,
                workDaysInspected: \count($workDays),
                byType: $byType,
                failures: $failures,
            ),
            $now,
        );
    }

    /**
     * Publica el desenlace de la pasada y devuelve el resultado sin tocarlo
     * (`incident_detection_*`, doc 02 §8.2, tarea 3.2).
     *
     * **Es lo ultimo que ocurre y no puede impedir nada** (regla dura 19), igual
     * que `anomalyMetrics`: cuando se llega aqui las incidencias ya estan
     * abiertas y el aviso al responsable ya ha salido.
     *
     * Se llama SIEMPRE, tambien en el camino sin centro y tambien cuando la
     * pasada no encontro nada: el silencio de esta serie es justo lo que la
     * alerta `DeteccionDeIncidenciasAusente` tiene que poder distinguir de una
     * noche tranquila.
     */
    private function measure(AnomalyScanResult $result, DateTimeImmutable $now): AnomalyScanResult
    {
        $this->detectionMetrics->scanCompleted(
            $result->workDaysInspected,
            $result->total(),
            $result->failures,
            $now,
        );

        return $result;
    }

    /**
     * Publica los hallazgos uno a uno, **aislando el fallo de cada uno**, y
     * devuelve cuantos no se pudieron abrir.
     *
     * El aislamiento tiene que estar aqui y no en el listener que abre la
     * incidencia: el despachador de Laravel es sincrono, asi que una excepcion en
     * cualquier suscriptor vuelve por esta pila. Sin este `try`, el hallazgo
     * numero tres de cuarenta abortaba los treinta y siete restantes, no salia el
     * resumen y el comando moria con una traza en vez de con un recuento.
     *
     * **Un fallo no se traga: se cuenta.** El comando termina con codigo distinto
     * de cero (`DetectIncidentsCommand`), pero lo que se pudo abrir queda abierto.
     * Un proceso que aborta a la mitad es peor que uno que informa: deja la
     * revision hecha a medias y sin decir por donde iba.
     *
     * **Hasta donde llega hoy ese codigo de salida** (tarea 3.2): a la serie
     * `incident_detection_last_failures` del colector *textfile*, que la regla
     * `DeteccionDeIncidenciasConFallos` evalua con `> 0` y enruta al IT del
     * cliente con su runbook; y al apunte `scheduler.command_failed` que escribe
     * `App\Support\Scheduling\LogScheduledCommandFailure` desde el
     * `->onFailure()` de `routes/console.php`, que es lo que lo hace localizable
     * en Loki. Con `runInBackground()` el codigo de salida NO produce ninguna
     * excepcion —`ScheduleRunCommand` solo lanza para las tareas en primer
     * plano—, asi que sin esas dos piezas no llegaba a ninguna parte.
     *
     * El log lleva `employee_uuid` y la clase de la excepcion, **nunca nombres ni
     * la traza** (regla dura 21): esto viaja a Loki y de ahi al paquete de
     * diagnostico (ADR-020).
     *
     * @param  list<DetectedAnomaly>  $anomalies
     */
    private function publishEach(array $anomalies): int
    {
        $failures = 0;

        foreach ($anomalies as $anomaly) {
            try {
                $this->events->publish(new AttendanceAnomalyDetected($anomaly));
            } catch (Throwable $failure) {
                $failures++;

                $this->logger->error('attendance.incident_not_opened', [
                    'employee_uuid' => $anomaly->employeeUuid,
                    'work_date' => $anomaly->workDate->isoDate,
                    'type' => $anomaly->type->value,
                    'shift_entry_uuid' => $anomaly->shiftEntryUuid,
                    'exception' => $failure::class,
                ]);
            }
        }

        return $failures;
    }

    /**
     * La politica con los seis umbrales del centro ya resueltos.
     *
     * `ClockingPolicies::forSettings()` es el **unico** sitio donde se construye
     * la de RN-07 y RN-08: una segunda copia seria la forma segura de que un
     * fichaje y la revision nocturna clasificaran distinto la misma duracion.
     */
    private function policyFor(int $siteId): AnomalyDetectionPolicy
    {
        $settings = $this->settings->forSite($siteId);

        return new AnomalyDetectionPolicy(
            ClockingPolicies::forSettings($settings),
            ReviewPolicy::toleratingSkewOfMinutes($settings->maximumClockSkewMinutes),
            $this->compliance->forSite($siteId),
        );
    }

    /**
     * Las jornadas de la pasada: las de la ventana **mas** las que tengan un
     * tramo abierto, sea cual sea su fecha.
     *
     * Se indexan por empleado y fecha para que una jornada que este en los dos
     * conjuntos —lo normal: el turno abierto de hoy— se revise una sola vez. Sin
     * esto cada hallazgo se emitiria dos veces; no se duplicaria en la tabla,
     * porque el indice unico parcial lo impide, pero si el trabajo y el recuento.
     *
     * @return list<WorkDay>
     */
    private function workDaysToInspect(DetectAnomaliesCommand $command, DateTimeZone $timezone, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone($timezone);
        $from = WorkDate::fromIsoDate($today->modify('-'.($command->lookbackDays - 1).' days')->format('Y-m-d'), $timezone);
        $to = WorkDate::fromIsoDate($today->format('Y-m-d'), $timezone);

        $byKey = [];

        foreach ([...$this->workDays->workDaysBetween($from, $to), ...$this->workDays->openWorkDays()] as $workDay) {
            $byKey[$workDay->employeeUuid().'|'.$workDay->workDate()->isoDate] = $workDay;
        }

        return array_values($byKey);
    }

    /**
     * Hallazgos que la politica SI evalua pero que esta pasada no convierte en
     * incidencia. Decision de producto del 30-08-2026 (doc 01 §4, nota sobre
     * RN-12): con ADR-024 la pausa son dos tramos y el quiosco todavia no
     * registra la intencion de «salgo a descansar» (RF-AT-12, tarea 3.5), asi
     * que en una instalacion donde la plantilla no ficha la pausa cada turno
     * de mas de seis horas abriria una incidencia `missing_break` —cientos a la
     * semana— sin que nadie pueda distinguir «no descanso» de «descanso y no lo
     * ficho». Una bandeja que no se puede vaciar es una bandeja que se deja de
     * mirar, y con ella las incidencias que si importan.
     *
     * La regla sigue enunciada, implementada en `AnomalyDetectionPolicy` y
     * cubierta por su prueba unitaria; lo que se suspende es SOLO la apertura de
     * la incidencia. **Desde la tarea 3.5 la suspension es del hotel y no del
     * producto**: RN-12 vuelve a abrir `missing_break` en cuanto la instalacion
     * active `ATTENDANCE_BREAK_CLOCKING` (RF-AT-12, ADR-024), y sigue suspendida
     * donde el quiosco no registra la pausa — abrirla alli seria senalar a quien
     * descanso sin fichar.
     *
     * **La lista ya no vive aqui** (tarea 5.2). Estaba escrita como una constante
     * privada de este caso de uso, y funcionaba mientras la mirase solo quien
     * filtra; dejo de funcionar en cuanto la pantalla del perfil de cumplimiento
     * empezo a decirle al cliente que cambiar el umbral de RN-12 haria que se
     * marcaran jornadas distintas —falso— y el asiento de `audit_log` empezo a
     * afirmar `affects_incident_detection: true` sobre un registro con valor
     * legal. `Product` no puede importar `Attendance` (doc 02 §1.6), asi que el
     * hecho subio a `Shared\Domain\ValueObject\ComplianceRuleSuspension` y aqui
     * se **recibe ya resuelto** (regla dura 14). Desde la 3.5 no hay ninguna
     * lista que vaciar: la suspension se construye desde
     * `ATTENDANCE_BREAK_CLOCKING`, asi que un hotel la reactiva desde el panel y
     * el filtro, la pantalla del perfil y el asiento se enteran solos.
     *
     * @param  list<WorkDay>  $workDays
     * @return list<DetectedAnomaly>
     */
    private function inspectWorkDays(
        array $workDays,
        AnomalyDetectionPolicy $policy,
        DateTimeImmutable $now,
        ComplianceRuleSuspension $suspension,
    ): array {
        $anomalies = [];

        foreach ($workDays as $workDay) {
            $anomalies = [
                ...$anomalies,
                ...$policy->inspect($workDay, $now, $this->previousShiftEndOf($workDay)),
            ];
        }

        return array_values(array_filter(
            $anomalies,
            static fn (DetectedAnomaly $anomaly): bool => ! $anomaly->type->openingIsSuspended($suspension),
        ));
    }

    /**
     * El fin del ultimo tramo anterior a esta jornada, que es la mitad que le
     * falta a RN-10.
     *
     * Se pregunta **por jornada** y no una vez por empleado: entre dos jornadas
     * revisadas puede haber otra que no entro en la ventana, y arrastrar en
     * memoria «la ultima que vi» daria un descanso mas largo del real justo en el
     * caso que la regla persigue. Es una consulta indexada por jornada revisada,
     * y esto corre una vez al dia.
     */
    private function previousShiftEndOf(WorkDay $workDay): ?DateTimeImmutable
    {
        $firstClockInAt = $workDay->firstClockInAt();

        if (! $firstClockInAt instanceof DateTimeImmutable) {
            return null;
        }

        return $this->workDays->lastClockOutBefore($workDay->employeeUuid(), $firstClockInAt);
    }

    /**
     * RN-15: los escaneos que llegaron con el reloj desviado por encima de la
     * tolerancia.
     *
     * **La marca ya existe** —`ReviewPolicy` la puso al registrar el escaneo— y
     * aqui solo se lee hacia atras: quien decide si el desfase supera el umbral
     * sigue siendo el dominio, con el mismo metodo que uso el quiosco.
     *
     * Los escaneos sin tramo —un rechazo, un anti-rebote— se saltan: sin
     * jornada a la que atribuir la incidencia, no hay nada que un responsable
     * pueda revisar en el registro.
     *
     * **Eso incluye los fichajes irreconciliables de RN-18**, que tambien nacen
     * marcados y con un desfase medido, a veces enorme —vienen de una cola que
     * drena dias despues—. No abren `clock_skew`: su hallazgo es otro, lo emite
     * {@see inspectOutOfOrderScans()} y decirlo dos veces pondria dos
     * incidencias sobre el mismo hecho. Que la condicion se cumpla por
     * `workDate === null` no es casualidad —un rechazo no produce tramo, y por
     * eso no tiene jornada— pero la prueba de la deteccion lo fija por si algun
     * dia el puerto empezara a traerla.
     *
     * @return list<DetectedAnomaly>
     */
    private function inspectFlaggedScans(
        DetectAnomaliesCommand $command,
        AnomalyDetectionPolicy $policy,
        int $siteId,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): array {
        $from = $now->modify('-'.$command->lookbackDays.' days');
        $anomalies = [];

        foreach ($this->flaggedScans->flaggedBetween($from, $now) as $scan) {
            $anomaly = $this->skewAnomaly($scan, $policy, $siteId, $timezone, $now);

            if ($anomaly instanceof DetectedAnomaly) {
                $anomalies[] = $anomaly;
            }
        }

        return $anomalies;
    }

    private function skewAnomaly(
        FlaggedScan $scan,
        AnomalyDetectionPolicy $policy,
        int $siteId,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): ?DetectedAnomaly {
        if ($scan->clockSkewSeconds === null || $scan->workDate === null) {
            return null;
        }

        $skew = ClockSkew::ofSeconds($scan->clockSkewSeconds);

        if (! $policy->skewRequiresValidation($skew)) {
            return null;
        }

        return new DetectedAnomaly(
            type: AnomalyType::CLOCK_SKEW,
            employeeUuid: $scan->employeeUuid,
            siteId: $siteId,
            workDate: WorkDate::fromIsoDate($scan->workDate, $timezone),
            shiftEntryUuid: $scan->shiftEntryUuid,
            detectedAt: $now,
            context: [
                // Con signo: distingue el quiosco adelantado del atrasado, que se
                // diagnostican distinto.
                'clock_skew_seconds' => $scan->clockSkewSeconds,
                'threshold_seconds' => $policy->review->skewToleranceSeconds,
            ],
        );
    }

    /**
     * RN-18: los fichajes que no se pudieron cuadrar con el registro.
     *
     * **La decision ya esta tomada y escrita** —`scan_events.result =
     * 'rejected_out_of_order'`, puesta por el agregado en el momento del
     * fichaje—, asi que aqui no se compara ninguna hora: se lee hacia atras y se
     * agrupa, exactamente como con el desfase de reloj. Volver a decidirlo esta
     * noche, con un registro que puede haber cambiado por una correccion (RN-13),
     * daria dos respuestas distintas sobre el mismo escaneo.
     *
     * **Una por empleado y jornada, no una por escaneo.** Una cola offline
     * desordenada trae varios seguidos y todos dicen lo mismo: «esta jornada de
     * esta persona no cuadra». La incidencia dice eso, y el `context` lleva el
     * **primero** —con que encontrarlo en el log— y cuantos fueron. La misma
     * garantia la sostiene ademas el esquema (`one_incident_per_finding` con
     * `NULLS NOT DISTINCT`), que es lo que hace idempotente repetir la pasada.
     *
     * La jornada se deriva del `occurred_at` del escaneo en la zona del centro
     * (RN-05): el escaneo no produjo tramo del que heredarla, y esa es la unica
     * fecha civil que el propio hecho sostiene.
     *
     * @return list<DetectedAnomaly>
     */
    private function inspectOutOfOrderScans(
        DetectAnomaliesCommand $command,
        int $siteId,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): array {
        $from = $now->modify('-'.$command->lookbackDays.' days');

        /** @var array<string, array{employeeUuid: string, workDate: WorkDate, scanId: string, occurredAt: DateTimeImmutable, scans: int}> $groups */
        $groups = [];

        foreach ($this->outOfOrderScans->outOfOrderBetween($from, $now) as $scan) {
            $workDate = WorkDate::fromInstant($scan->occurredAt, $timezone);
            $key = $scan->employeeUuid.'|'.$workDate->isoDate;

            if (isset($groups[$key])) {
                $groups[$key]['scans']++;

                // El primero se queda: el puerto entrega en orden ascendente de
                // `occurred_at`, asi que el que ya esta es el mas antiguo.
                continue;
            }

            $groups[$key] = [
                'employeeUuid' => $scan->employeeUuid,
                'workDate' => $workDate,
                'scanId' => $scan->scanId,
                'occurredAt' => $scan->occurredAt,
                'scans' => 1,
            ];
        }

        $anomalies = [];

        foreach ($groups as $group) {
            $anomalies[] = new DetectedAnomaly(
                type: AnomalyType::OUT_OF_ORDER_SCAN,
                employeeUuid: $group['employeeUuid'],
                siteId: $siteId,
                workDate: $group['workDate'],
                // El escaneo no produjo tramo, y el que estaba abierto no es el
                // problema sino el contexto: la incidencia es de la jornada.
                shiftEntryUuid: null,
                detectedAt: $now,
                context: [
                    // Con que encontrar el fichaje en el log y con que entender
                    // que estaba intentando registrar. **Sin datos personales**
                    // (regla dura 21): un UUID de escaneo y un instante.
                    'scan_id' => $group['scanId'],
                    // La MISMA forma que cualquier instante de la API —sufijo
                    // `Z`, esquema `UtcTimestamp`— y no `ATOM`, que escribe
                    // `+00:00`: este valor se pinta en la bandeja junto a los
                    // demas y viaja en la exportacion, y dos formatos de fecha en
                    // la misma pantalla son dos formatos que alguien parsea mal.
                    'occurred_at' => UtcInstant::of($group['occurredAt']),
                    'scans' => $group['scans'],
                ],
            );
        }

        return $anomalies;
    }

    /**
     * @param  list<DetectedAnomaly>  $anomalies
     * @return array<string, int>
     */
    private function tally(array $anomalies): array
    {
        $byType = [];

        foreach ($anomalies as $anomaly) {
            $byType[$anomaly->type->value] = ($byType[$anomaly->type->value] ?? 0) + 1;
        }

        ksort($byType);

        return $byType;
    }
}
