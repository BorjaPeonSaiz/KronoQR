<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Port\CardFingerprintOwners;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\DiscardedScanReportLog;
use App\Modules\Attendance\Application\Port\DiscardedScanReportRecord;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\RecordedScanFailures;
use App\Modules\Attendance\Application\Port\ScanDiscardSettings;
use App\Modules\Attendance\Application\Port\ScanFailureAttempt;
use App\Modules\Attendance\Application\Port\ScanFailureClassifier;
use App\Modules\Attendance\Application\Port\ScanFailureLedger;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\Event\UnprocessableScanDiscarded;
use App\Modules\Attendance\Domain\Policy\DiscardedScanAttributionPolicy;
use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\UnprocessableScanVerdict;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Support\UtcInstant;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * **El unico sitio que decide si un fichaje que el servidor no consigue
 * procesar se descarta** (RN-22, ADR-061, que enmienda ADR-047).
 *
 * Envuelve las tres puertas de escritura del fichaje —`/scan/batch` y `/scan`
 * por {@see GuardedScanRegistration}, `/scan/pin` por
 * {@see GuardedPinScanRegistration}— y ninguna puede saltarselo
 * (`UnprocessableScanGuardCoverageTest`). En el camino feliz no hace nada: ni
 * una lectura ni una escritura (RNF-P-01).
 *
 * ## Cuando el caso de uso lanza
 *
 * 1. **Clasificar** ({@see ScanFailureClassifier}): una dependencia caida, una
 *    contencion o un rechazo de negocio no cuentan. Se relanza tal cual y el
 *    desenlace es el de siempre: `503 scan-not-processed` en el lote, `500`
 *    suelto. El quiosco conserva y reintenta.
 * 2. **Ya descartado**: un reenvio de tarjeta que vuelve a fallar antes de
 *    llegar al `UNIQUE` de `scan_events` no veria la fila del descarte. Aqui se
 *    busca —solo en el camino del fallo, que no cuesta nada al resto— y se
 *    repite el descarte (regla dura 8).
 * 3. **Apuntar** el intento en `scan_processing_failures`, en su propia
 *    transaccion, ya deshecha la del caso de uso ({@see ScanFailureLedger}).
 * 4. **Decidir** con {@see UnprocessableScanPolicy}, el reloj del servidor, el
 *    umbral de la configuracion y los descartes del quiosco en 24 h.
 *    - `KeepRetrying`: se relanza. Como hoy.
 *    - `BudgetExhausted`: se relanza **y se avisa**: el cupo esta agotado y la
 *      cola vuelve a atascarse a proposito, con alerta critica.
 *    - `Discard`: se descarta (abajo) y se responde `422 scan-discarded`.
 *
 * ## El descarte, en una transaccion nueva
 *
 * La fila de `scan_events` con `discarded_unprocessable` —la que da la
 * idempotencia—, la de `discarded_scan_reports` con `source = server` —la que
 * lee la revision diaria para abrir `discarded_scan`— y el asiento
 * `scan.discarded` (el evento {@see UnprocessableScanDiscarded}, que
 * `Compliance` convierte en asiento dentro de la misma transaccion). **Nunca un
 * tramo**: `WorkDay` no se toca (ADR-061 §3). La atribucion es la del aviso del
 * quiosco ({@see DiscardedScanAttributionPolicy}); si lanza —quiza el resolutor
 * era lo roto— el descarte queda sin atribuir y solo lo ve la alerta.
 *
 * ## Ante la duda, conservar
 *
 * Cualquier fallo de esta contabilidad —el apunte, la lectura del cupo, la
 * configuracion, la transaccion del descarte— deja el comportamiento de antes
 * de ADR-061: se relanza la excepcion ORIGINAL del caso de uso y el fichaje se
 * queda en la cola. Lo deja escrito en el log, sin mensaje (regla dura 21).
 *
 * ## Tiempo constante (RS-03)
 *
 * El descarte verifica la firma de la tarjeta para atribuirlo, y su duracion no
 * debe decir si la tarjeta era de alguien: todo `scan-discarded` se rellena
 * hasta el mismo suelo que el aviso del quiosco (el doble del de RS-03, lo fija
 * `AttendanceServiceProvider`).
 */
final readonly class UnprocessableScanGuard
{
    /** El `type` del problema que responde un descarte (contrato, `ScanDiscarded`). */
    public const string PROBLEM_TYPE = 'urn:kronoqr:problem:scan-discarded';

    /** El codigo con el que se responde: el quiosco 2.2.x saca de la cola todo `200` o `422` (ADR-061 §4). */
    public const int HTTP_STATUS = 422;

    /**
     * Cuanto se mira hacia atras, desde el fichaje, para atribuir por la huella
     * (ADR-061, A2). Acota una consulta sin indice propio; una tarjeta que no se
     * ha usado en seis meses no da un titular fiable.
     */
    public const int FINGERPRINT_LOOKBACK_DAYS = 180;

    public function __construct(
        private ScanFailureClassifier $classifier,
        private ScanFailureLedger $ledger,
        private ScanDiscardSettings $settings,
        private ScanLog $scans,
        private DiscardedScanReportLog $reports,
        private CredentialResolver $credentials,
        private EmployeeDirectory $employees,
        private EventPublisher $events,
        private ScanMetrics $metrics,
        private Clock $clock,
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
        private ConstantTimeFloor $floor,
        private CardFingerprintOwners $fingerprints,
    ) {}

    /**
     * @param  callable(): RegisterScanResult  $register  El caso de uso, sin envolver.
     */
    public function register(GuardedScan $scan, callable $register): RegisterScanResult
    {
        $startedAt = $this->floor->startedAt();

        try {
            return $register();
        } catch (Throwable $failure) {
            $discarded = $this->settle($scan, $failure);

            if (! $discarded instanceof RegisterScanResult) {
                throw $failure;
            }

            $this->floor->padTo($startedAt);

            return $discarded;
        }
    }

    /**
     * El descarte, o `null` si el fichaje se queda en la cola.
     */
    private function settle(GuardedScan $scan, Throwable $failure): ?RegisterScanResult
    {
        try {
            if (! $this->classifier->counts($failure)) {
                return null;
            }

            return UseCaseInstrumentation::run(
                'attendance.settle_unprocessable_scan',
                fn (): ?RegisterScanResult => $this->decide($scan, $failure),
                static fn (?RegisterScanResult $result): array => [
                    'scan.discarded' => $result instanceof RegisterScanResult,
                ],
            );
        } catch (Throwable $bookkeeping) {
            // Ante la duda, conservar. La clase y nunca el mensaje: el de una
            // `QueryException` lleva el SQL con sus valores (regla dura 21).
            $this->logger->warning('attendance.scan_discard_bookkeeping_failed', [
                'scan_id' => $scan->scanId,
                'device_id' => $scan->deviceUuid,
                'failure' => $bookkeeping::class,
            ]);

            return null;
        }
    }

    private function decide(GuardedScan $scan, Throwable $failure): ?RegisterScanResult
    {
        if ($this->scans->find($scan->scanId) !== null) {
            // Ya decidido. Si fue un descarte se repite; cualquier otra fila no
            // es asunto de este guardian —el siguiente reenvio la encontrara por
            // el camino de siempre— y el fallo sigue su curso.
            return $this->alreadyDiscarded($scan);
        }

        $now = $this->clock->now();

        $failures = $this->ledger->record(new ScanFailureAttempt(
            scanId: $scan->scanId,
            deviceId: $scan->deviceId,
            origin: $scan->origin,
            payloadFingerprint: $scan->contentFingerprint,
            failedAt: $now,
            exceptionClass: $failure::class,
        ));

        if (! $failures instanceof RecordedScanFailures) {
            // Otro quiosco u otro contenido que el del primer intento: se apunta,
            // pero no empuja a nadie hacia el descarte (ADR-061 §1).
            return null;
        }

        $policy = new UnprocessableScanPolicy;

        $verdict = $policy->decide(
            $failures->history,
            $this->ledger->serverDiscardsSince($scan->deviceId, $policy->budgetWindowStartsAt($now)),
            $now,
            $this->settings->threshold(),
        );

        if ($verdict === UnprocessableScanVerdict::BudgetExhausted) {
            $this->metrics->scanDiscardBudgetExhausted($scan->deviceUuid);
            $this->logger->warning('attendance.scan_discard_budget_exhausted', [
                'scan_id' => $scan->scanId,
                'device_id' => $scan->deviceUuid,
                'attempts' => $failures->history->attempts,
            ]);

            return null;
        }

        if ($verdict !== UnprocessableScanVerdict::Discard) {
            return null;
        }

        return $this->discard($scan, $failures, $now);
    }

    private function discard(GuardedScan $scan, RecordedScanFailures $failures, DateTimeImmutable $now): ?RegisterScanResult
    {
        $attribution = $this->attribute($scan, $now);
        $history = $failures->history;

        $written = $this->connection->transaction(function () use ($scan, $attribution, $history, $now): bool {
            // Primero la fila que da la idempotencia. Si el `scan_id` ya esta en
            // `scan_events` —un envio que gano entre tanto—, no se descarta nada
            // y no se escribe nada mas.
            $recorded = $this->scans->record(new ScanRecord(
                scanId: $scan->scanId,
                deviceId: $scan->deviceId,
                // Solo una tarjeta autentica pone persona en `scan_events`, como en
                // RN-20 (ADR-061 §6). Por codigo o por huella, nadie: el codigo no
                // autentica (ADR-043) y `claimed_employee_id` abriria la
                // incidencia de RN-19; el dueño queda en `discarded_scan_reports`.
                employeeUuid: $attribution->authenticates() ? $attribution->ownerUuid : null,
                occurredAt: $scan->occurredAt,
                recordedAt: $now,
                origin: $scan->origin,
                intent: $scan->intent,
                result: ScanResult::DISCARDED_UNPROCESSABLE,
                // Las columnas minimas: si el veneno estuviera en `client_meta`,
                // no se repite aqui.
                payloadFingerprint: $scan->scanEventFingerprint(),
            ));

            if (! $recorded) {
                return false;
            }

            $this->reports->record(new DiscardedScanReportRecord(
                scanId: $scan->scanId,
                deviceId: $scan->deviceId,
                origin: $scan->origin,
                occurredAt: $scan->occurredAt,
                discardedAt: $now,
                recordedAt: $now,
                httpStatus: self::HTTP_STATUS,
                problemType: self::PROBLEM_TYPE,
                attribution: $attribution,
                source: DiscardedScanReportRecord::SOURCE_SERVER,
                failedAttempts: $history->attempts,
                firstFailedAt: $history->firstFailedAt,
            ));

            $this->events->publish(new UnprocessableScanDiscarded(
                scanId: $scan->scanId,
                deviceId: $scan->deviceId,
                deviceUuid: $scan->deviceUuid,
                origin: $scan->origin,
                scannedAt: $scan->occurredAt,
                discardedAt: $now,
                history: $history,
                attribution: $attribution->method,
                employeeUuid: $attribution->ownerUuid,
            ));

            return true;
        });

        if (! $written) {
            // Otro envio del mismo fichaje gano la carrera: si lo que escribio
            // es el mismo descarte, se repite (regla dura 8); si es otra cosa,
            // el fallo sigue su curso y el reenvio lo encontrara.
            return $this->alreadyDiscarded($scan);
        }

        $this->metrics->serverDiscardedScan($scan->deviceUuid, $attribution->isAttributed());

        // Un descarte del servidor es un defecto del producto que hay que
        // reproducir: las clases de excepcion vistas son la pista. Ni un nombre
        // ni un mensaje (regla dura 21, ADR-048).
        $this->logger->warning('attendance.scan_discarded_after_failures', [
            'scan_id' => $scan->scanId,
            'device_id' => $scan->deviceUuid,
            'employee_uuid' => $attribution->ownerUuid,
            'attempts' => $history->attempts,
            'first_failed_at' => UtcInstant::of($history->firstFailedAt),
            'exception_classes' => $failures->exceptionClasses,
            'attributed' => $attribution->isAttributed(),
        ]);

        return RegisterScanResult::discarded(
            scanId: $scan->scanId,
            occurredAt: $scan->occurredAt,
            recordedAt: $now,
            employeeUuid: $attribution->ownerUuid,
        );
    }

    /**
     * El descarte ya escrito de este fichaje, repetido tal cual, o `null` si lo
     * que hay en `scan_events` no es un descarte.
     */
    private function alreadyDiscarded(GuardedScan $scan): ?RegisterScanResult
    {
        $recorded = $this->scans->find($scan->scanId);

        if ($recorded === null || ! $recorded->result->isDiscard()) {
            return null;
        }

        return RegisterScanResult::discarded(
            scanId: $scan->scanId,
            occurredAt: $recorded->occurredAt,
            recordedAt: $recorded->recordedAt,
            employeeUuid: $recorded->employeeUuid,
            isReplay: true,
        );
    }

    /**
     * La atribucion del aviso del quiosco (ADR-047 §2): tarjeta autentica, o
     * codigo de una persona que puede fichar. Nunca el `token_hash` del padron.
     *
     * **Una tarjeta que no se atribuye por su firma busca a su titular por la
     * huella del contenido** (ADR-061, A2): el aceptado mas reciente con el mismo
     * `payload_fingerprint`, dentro de {@see self::FINGERPRINT_LOOKBACK_DAYS}
     * dias antes del fichaje. Tambien si el resolutor lanza, que puede ser
     * justamente lo roto.
     *
     * Si todo lanza, `none`: atribuir es accesorio, registrar el descarte no.
     */
    private function attribute(GuardedScan $scan, DateTimeImmutable $now): DiscardedScanAttribution
    {
        $policy = new DiscardedScanAttributionPolicy;

        try {
            if ($scan->origin !== ScanOrigin::QR_KIOSK) {
                return $policy->forEmployeeCode($this->employees->findByCode($scan->employeeCode ?? ''));
            }

            $byCard = $policy->forCard($this->credentials->resolve($scan->qrPayload ?? ''), $scan->occurredAt, $now);

            if ($byCard->isAttributed()) {
                return $byCard;
            }
        } catch (Throwable $failure) {
            $this->logger->warning('attendance.scan_discard_unattributed', [
                'scan_id' => $scan->scanId,
                'device_id' => $scan->deviceUuid,
                'failure' => $failure::class,
            ]);

            if ($scan->origin !== ScanOrigin::QR_KIOSK) {
                return DiscardedScanAttribution::none();
            }
        }

        return $this->attributeByFingerprint($scan, $now);
    }

    /**
     * El titular por la huella del contenido, o `none` (ADR-061, A2).
     */
    private function attributeByFingerprint(GuardedScan $scan, DateTimeImmutable $now): DiscardedScanAttribution
    {
        try {
            $owner = $this->fingerprints->latestAcceptedOwner(
                $scan->contentFingerprint,
                $scan->occurredAt->modify('-'.self::FINGERPRINT_LOOKBACK_DAYS.' days'),
                $now,
            );
        } catch (Throwable $failure) {
            $this->logger->warning('attendance.scan_discard_unattributed', [
                'scan_id' => $scan->scanId,
                'device_id' => $scan->deviceUuid,
                'failure' => $failure::class,
            ]);

            return DiscardedScanAttribution::none();
        }

        return $owner === null ? DiscardedScanAttribution::none() : DiscardedScanAttribution::fingerprintMatch($owner);
    }
}
