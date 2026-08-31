<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\DetectAnomaliesCommand;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\FlaggedScan;
use App\Modules\Attendance\Application\Port\FlaggedScans;
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
        private InstallationSiteProvider $sites,
        private OperationalSettingsProvider $settings,
        private CompliancePolicyProvider $compliance,
        private EventPublisher $events,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(DetectAnomaliesCommand $command): AnomalyScanResult
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return AnomalyScanResult::withoutSite();
        }

        $now = $this->clock->now();
        $timezone = new DateTimeZone($site->timezone);
        $policy = $this->policyFor($site->id);

        $workDays = $this->workDaysToInspect($command, $timezone, $now);

        $anomalies = [
            ...$this->inspectWorkDays($workDays, $policy, $now),
            ...$this->inspectFlaggedScans($command, $policy, $site->id, $timezone, $now),
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

        return AnomalyScanResult::of(
            daysInspected: $command->lookbackDays,
            workDaysInspected: \count($workDays),
            byType: $this->tally($anomalies),
            failures: $failures,
        );
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
     * **Hasta donde llega hoy ese codigo de salida:** al log del planificador y a
     * quien encadene el comando en un script. No hay `onFailure()`, ni regla de
     * Loki, ni serie `..._last_failures` que lo convierta en una alerta — eso es
     * de la tarea 3.2 y esta anotado alli.
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
     * la incidencia. La tarea 3.5 la reactiva cuando exista la pausa declarada.
     *
     * **La lista ya no vive aqui** (tarea 5.2). Estaba escrita como una constante
     * privada de este caso de uso, y funcionaba mientras la mirase solo quien
     * filtra; dejo de funcionar en cuanto la pantalla del perfil de cumplimiento
     * empezo a decirle al cliente que cambiar el umbral de RN-12 haria que se
     * marcaran jornadas distintas —falso— y el asiento de `audit_log` empezo a
     * afirmar `affects_incident_detection: true` sobre un registro con valor
     * legal. `Product` no puede importar `Attendance` (doc 02 §1.6), asi que el
     * hecho subio a `Shared\Domain\ValueObject\ComplianceRuleSuspension` y aqui
     * se **consulta**. La 3.5 sigue reactivando la regla vaciando una sola
     * lista, y ahora el panel y el asiento se enteran solos.
     *
     * @param  list<WorkDay>  $workDays
     * @return list<DetectedAnomaly>
     */
    private function inspectWorkDays(array $workDays, AnomalyDetectionPolicy $policy, DateTimeImmutable $now): array
    {
        $anomalies = [];

        foreach ($workDays as $workDay) {
            $anomalies = [
                ...$anomalies,
                ...$policy->inspect($workDay, $now, $this->previousShiftEndOf($workDay)),
            ];
        }

        return array_values(array_filter(
            $anomalies,
            static fn (DetectedAnomaly $anomaly): bool => ! $anomaly->type->openingIsSuspended(),
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
