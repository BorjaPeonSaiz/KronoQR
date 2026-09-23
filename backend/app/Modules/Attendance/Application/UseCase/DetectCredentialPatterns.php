<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\DetectPatternsCommand;
use App\Modules\Attendance\Application\Port\AnomalousPatternHistory;
use App\Modules\Attendance\Application\Port\AnomalyMetrics;
use App\Modules\Attendance\Application\Port\CredentialScans;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\PatternDetectionMetrics;
use App\Modules\Attendance\Domain\Event\AttendanceAnomalyDetected;
use App\Modules\Attendance\Domain\Event\AttendanceReviewCompleted;
use App\Modules\Attendance\Domain\Policy\CredentialPatternPolicy;
use App\Modules\Attendance\Domain\ValueObject\CredentialPatternThresholds;
use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\PatternReviewState;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\Exception\ConflictingFinding;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La deteccion de **patrones anomalos de uso de credencial** (RF-PR-06, RN-16,
 * tarea 3.11).
 *
 * Es la contrapartida explicita de haber descartado la biometria (ADR-009,
 * regla dura 20): el prestamo fisico de la tarjeta es el unico fraude que la
 * firma HMAC no impide (doc 01 §8.1), y esta pasada es lo que hace defendible
 * esa decision.
 *
 * ## Lo que NO hace, y es lo que la hace publicable
 *
 * No anula, no marca y no corrige **ningun** fichaje (reglas duras 5 y 19): sus
 * dos puertos de lectura no exponen ningun `save()`, y ni siquiera mira
 * `shift_entries`. No concluye que haya fraude: emite un indicio con sus numeros
 * y lo pone en la bandeja de una persona, que lo contrasta con la supervision
 * presencial siguiendo el runbook `patron-anomalo-credencial.md`.
 *
 * ## Calcada de {@see DetectAttendanceAnomalies}, a proposito
 *
 * Mismo camino de publicacion —`AttendanceAnomalyDetected` por hallazgo con el
 * fallo aislado uno a uno, `AttendanceReviewCompleted` al final para que el
 * responsable reciba un **resumen por ejecucion** y no un correo por hallazgo—,
 * misma resolucion del centro y mismos umbrales por puerto. Lo unico distinto es
 * que hay: alli, jornadas; aqui, escaneos de quiosco.
 *
 * ## Los tres umbrales llegan resueltos (regla dura 14)
 *
 * `ATTENDANCE_PATTERN_WINDOW_SECONDS`, `ATTENDANCE_PATTERN_MIN_REPEATS` y
 * `ATTENDANCE_MIN_TRANSIT_SECONDS` salen de `installation_settings` por
 * `OperationalSettingsProvider`, **una sola vez por pasada**: leerlos por
 * hallazgo podria dar dos respuestas si alguien cambia el ajuste a mitad de la
 * noche.
 *
 * ## Una instalacion, un centro
 *
 * Sin centro —instalacion recien instalada, RF-PD-03— la pasada no hace nada y
 * **no falla**: se publica la frescura con ceros para que
 * `DeteccionDePatronesAusente` distinga eso de un planificador parado.
 */
final readonly class DetectCredentialPatterns
{
    public function __construct(
        private CredentialScans $scans,
        /** Que hay ya en la bandeja sobre los indicios de cada persona (decision 13c). */
        private AnomalousPatternHistory $history,
        private InstallationSiteProvider $sites,
        private OperationalSettingsProvider $settings,
        private EventPublisher $events,
        private AnomalyMetrics $anomalyMetrics,
        private PatternDetectionMetrics $detectionMetrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(DetectPatternsCommand $command): PatternScanResult
    {
        $site = $this->sites->installationSite();
        $now = $this->clock->now();

        if ($site === null) {
            return $this->measure(PatternScanResult::withoutSite(), $now);
        }

        $timezone = new DateTimeZone($site->timezone);
        $settings = $this->settings->forSite($site->id);

        $policy = new CredentialPatternPolicy(new CredentialPatternThresholds(
            windowSeconds: $settings->patternWindowSeconds,
            minRepeats: $settings->patternMinRepeats,
            minTransitSeconds: $settings->minimumTransitSeconds,
            maximumClockSkewMinutes: $settings->maximumClockSkewMinutes,
        ));

        $scans = $this->scans->kioskScansBetween(
            $now->modify('-'.$command->lookbackDays.' days'),
            $now,
            $timezone,
        );

        $anomalies = $policy->inspect($scans, $site->id, $now, $this->reviewStatesFor($scans));

        $failures = $this->publishEach($anomalies);

        // El aviso al responsable es **un resumen por ejecucion** (RF-PR-01) y lo
        // compone `Compliance`, que es quien tiene las incidencias y sus
        // responsables. Se publica tambien cuando algun hallazgo fallo: lo que se
        // abrio hay que avisarlo.
        $this->events->publish(new AttendanceReviewCompleted(
            siteId: $site->id,
            completedAt: $now,
            daysInspected: $command->lookbackDays,
            anomaliesDetected: \count($anomalies),
        ));

        $byPattern = $this->tally($anomalies);

        // `anomalous_patterns_detected_total{pattern}` (doc 02 §8.2). Las dos
        // etiquetas nuevas son `kiosk_coincidence` e `impossible_sequence`, y
        // conviven con las que ya publica `attendance:detect-incidents` sin
        // tocarlas. Va detras del aviso a proposito: medir es lo ultimo y no
        // puede impedir nada de lo anterior.
        $this->anomalyMetrics->anomaliesDetected($byPattern);

        return $this->measure(
            PatternScanResult::of(
                daysInspected: $command->lookbackDays,
                scansInspected: \count($scans),
                byPattern: $byPattern,
                failures: $failures,
            ),
            $now,
        );
    }

    /**
     * Que se ha hecho ya con los indicios de coincidencia de cada persona de la
     * ventana (decision 13c de la ficha).
     *
     * **Una sola consulta por pasada**, con todos los empleados que aparecen en
     * los escaneos: preguntar por persona serian doscientos viajes para
     * responder algo que cabe en uno. El resultado entra en la politica ya
     * resuelto (regla dura 14): el dominio no consulta la bandeja.
     *
     * Solo se pregunta por `kiosk_coincidence`. `impossible_sequence` no lo
     * necesita: describe un hecho de una jornada concreta —dos escaneos con hora
     * y quiosco— y `one_incident_per_finding` ya lo deduplica sin ayuda.
     *
     * @param  list<CredentialScan>  $scans
     * @return array<string, PatternReviewState>
     */
    private function reviewStatesFor(array $scans): array
    {
        $uuids = [];

        foreach ($scans as $scan) {
            $uuids[$scan->employeeUuid] = true;
        }

        return $this->history->forEmployees(
            array_keys($uuids),
            CredentialPatternPolicy::KIOSK_COINCIDENCE,
        );
    }

    /**
     * Publica el desenlace de la pasada y devuelve el resultado sin tocarlo
     * (`pattern_detection_*`, doc 02 §8.2).
     *
     * **Es lo ultimo que ocurre y no puede impedir nada** (regla dura 19).
     * Se llama SIEMPRE, tambien en el camino sin centro y tambien cuando no se
     * encontro nada: el silencio de esta serie es justo lo que la alerta
     * `DeteccionDePatronesAusente` tiene que poder distinguir de una noche
     * tranquila.
     */
    private function measure(PatternScanResult $result, DateTimeImmutable $now): PatternScanResult
    {
        $this->detectionMetrics->scanCompleted($result->failures, $now);

        return $result;
    }

    /**
     * Publica los hallazgos uno a uno, **aislando el fallo de cada uno**, y
     * devuelve cuantos no se pudieron abrir.
     *
     * El aislamiento tiene que estar aqui y no en el listener que abre la
     * incidencia: el despachador de Laravel es sincrono, asi que una excepcion en
     * cualquier suscriptor vuelve por esta pila. Un proceso que aborta a la mitad
     * deja la revision hecha a medias y sin decir por donde iba.
     *
     * El log lleva `employee_uuid` y la clase de la excepcion, **nunca nombres ni
     * la traza** (regla dura 21): esto viaja a Loki y de ahi al paquete de
     * diagnostico (ADR-020). **Tampoco lleva la contraparte**: el log tecnico no
     * es el sitio donde se relaciona a dos personas.
     *
     * @param  list<DetectedAnomaly>  $anomalies
     */
    private function publishEach(array $anomalies): int
    {
        $failures = 0;

        foreach ($anomalies as $anomaly) {
            try {
                $this->events->publish(new AttendanceAnomalyDetected($anomaly));
            } catch (ConflictingFinding) {
                $failures++;

                // No es una averia: es un indicio que no cabe en la bandeja
                // porque otro distinto de la misma persona y el mismo dia ya
                // ocupa esa cuadrupla. Seguir teniendo una sola incidencia es
                // correcto; que nadie lo supiera, no (decision 13d).
                $this->logger->warning('attendance.pattern_incident_collided', [
                    'employee_uuid' => $anomaly->employeeUuid,
                    'work_date' => $anomaly->workDate->isoDate,
                    'pattern' => $this->patternOf($anomaly),
                ]);
            } catch (Throwable $failure) {
                $failures++;

                $this->logger->error('attendance.pattern_incident_not_opened', [
                    'employee_uuid' => $anomaly->employeeUuid,
                    'work_date' => $anomaly->workDate->isoDate,
                    'pattern' => $this->patternOf($anomaly),
                    'exception' => $failure::class,
                ]);
            }
        }

        return $failures;
    }

    /**
     * Los hallazgos de la pasada agrupados por patron, que es la etiqueta de
     * `anomalous_patterns_detected_total`.
     *
     * Por patron y no por tipo: los dos hallazgos comparten
     * `AnomalyType::ANOMALOUS_PATTERN` —es el valor de `incidents.type`, y el
     * catalogo del doc 01 §5.5 no se amplia por esto— y lo que distingue una
     * coincidencia sistematica de una imposibilidad fisica es `context.pattern`.
     * Contarlos juntos haria ilegible la serie que el cuadro «Negocio» grafica.
     *
     * @param  list<DetectedAnomaly>  $anomalies
     * @return array<string, int>
     */
    private function tally(array $anomalies): array
    {
        $byPattern = [];

        foreach ($anomalies as $anomaly) {
            $pattern = $this->patternOf($anomaly);
            $byPattern[$pattern] = ($byPattern[$pattern] ?? 0) + 1;
        }

        ksort($byPattern);

        return $byPattern;
    }

    /**
     * El patron de un hallazgo, leido de su contexto.
     *
     * Cae al valor del tipo si faltara: una serie con una etiqueta rara se ve; un
     * `TypeError` en la ultima linea de la pasada tiraria una revision ya hecha.
     */
    private function patternOf(DetectedAnomaly $anomaly): string
    {
        $pattern = $anomaly->context['pattern'] ?? $anomaly->type->value;

        return \is_string($pattern) ? $pattern : $anomaly->type->value;
    }
}
