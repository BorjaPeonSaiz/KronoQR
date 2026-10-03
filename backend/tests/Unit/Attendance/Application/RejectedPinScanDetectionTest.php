<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\DetectAnomaliesCommand;
use App\Modules\Attendance\Application\Port\AnomalyMetrics;
use App\Modules\Attendance\Application\Port\DiscardedScans;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\FlaggedScans;
use App\Modules\Attendance\Application\Port\IncidentDetectionMetrics;
use App\Modules\Attendance\Application\Port\OutOfOrderScans;
use App\Modules\Attendance\Application\Port\RejectedPinScans;
use App\Modules\Attendance\Application\Port\WithdrawnCredentialScans;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Application\UseCase\AnomalyScanResult;
use App\Modules\Attendance\Application\UseCase\DetectAttendanceAnomalies;
use App\Modules\Attendance\Domain\Event\AttendanceAnomalyDetected;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use App\Modules\Shared\Domain\ValueObject\KioskUpdateWindow;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;
use Psr\Log\NullLogger;
use Tests\Support\Time\FixedClock;

/*
 * RN-19 en la revision diaria, con dobles (ADR-043, RF-PR-01).
 *
 * Lo que se fija aqui es el ALGORITMO de `inspectRejectedPinScans()`: que
 * descarta lo subsanado, que agrupa por persona y jornada del centro, que el
 * contexto lleva el primero y los tres recuentos, y que el recuento por tipo lo
 * incluye. Que la consulta filtre bien los resultados que subsanan, la ventana
 * de `recorded_at` y el `CHECK` es de la integracion (`EloquentRejectedPinScans`,
 * `PinClaimSchemaTest`).
 *
 * El centro esta en Madrid y el reloj fijo el 15-03-2026 a las 12:00 UTC.
 */

const REJECTED_PIN_DETECTION_NOW = '2026-03-15 12:00:00';

const REJECTED_PIN_DETECTION_ANA = '0199f0c2-0000-7000-8000-00000000000a';

const REJECTED_PIN_DETECTION_BEA = '0199f0c2-0000-7000-8000-00000000000b';

final class InMemoryRejectedPinScans implements RejectedPinScans
{
    /** @var list<array{string, DateTimeImmutable, DateTimeImmutable}> */
    public array $recoveryQueries = [];

    /**
     * @param  list<RejectedPinAttempt>  $attempts
     * @param  array<string, list<DateTimeImmutable>>  $recovering
     */
    public function __construct(private array $attempts, private array $recovering = []) {}

    public function rejectedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
    {
        $inWindow = array_values(array_filter(
            $this->attempts,
            static fn (RejectedPinAttempt $a): bool => $a->recordedAt >= $fromRecordedAt && $a->recordedAt <= $toRecordedAt,
        ));

        usort($inWindow, static fn (RejectedPinAttempt $a, RejectedPinAttempt $b): int => [$a->occurredAt, $a->scanId] <=> [$b->occurredAt, $b->scanId]);

        return $inWindow;
    }

    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->recoveryQueries[] = [$employeeUuid, $from, $to];

        return array_values(array_filter(
            $this->recovering[$employeeUuid] ?? [],
            static fn (DateTimeImmutable $at): bool => $at >= $from && $at <= $to,
        ));
    }
}

function rejectedPinDetectionUtc(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

function rejectedPinDetectionAttempt(string $scanId, string $claimant, string $occurredAt, ?string $recordedAt = null, bool $lockout = false): RejectedPinAttempt
{
    return new RejectedPinAttempt(
        scanId: $scanId,
        claimantUuid: $claimant,
        occurredAt: rejectedPinDetectionUtc($occurredAt),
        recordedAt: rejectedPinDetectionUtc($recordedAt ?? $occurredAt),
        lockout: $lockout,
    );
}

/**
 * @return array{result: AnomalyScanResult, anomalies: list<DetectedAnomaly>, metrics: array<string, int>, port: InMemoryRejectedPinScans}
 */
function rejectedPinDetectionRun(InMemoryRejectedPinScans $port, int $lookbackDays = 7): array
{
    $events = new class implements EventPublisher
    {
        /** @var list<DomainEvent> */
        public array $published = [];

        public function publish(DomainEvent ...$events): void
        {
            foreach ($events as $event) {
                $this->published[] = $event;
            }
        }
    };

    $anomalyMetrics = new class implements AnomalyMetrics
    {
        /** @var array<string, int> */
        public array $byPattern = [];

        public function anomaliesDetected(array $byPattern): void
        {
            $this->byPattern = $byPattern;
        }
    };

    $handler = new DetectAttendanceAnomalies(
        workDays: new class implements WorkDayLedger
        {
            public function openWorkDays(): array
            {
                return [];
            }

            public function workDaysBetween(WorkDate $from, WorkDate $to): array
            {
                return [];
            }

            public function workDayOf(string $employeeUuid, WorkDate $workDate): ?WorkDay
            {
                return null;
            }

            public function lastClockOutBefore(string $employeeUuid, DateTimeImmutable $instant): ?DateTimeImmutable
            {
                return null;
            }
        },
        flaggedScans: new class implements FlaggedScans
        {
            public function flaggedBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }
        },
        outOfOrderScans: new class implements OutOfOrderScans
        {
            public function outOfOrderBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }
        },
        rejectedPinScans: $port,
        withdrawnCredentialScans: new class implements WithdrawnCredentialScans
        {
            public function withdrawnBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
            {
                return [];
            }
        },
        discardedScans: new class implements DiscardedScans
        {
            public function attributedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
            {
                return [];
            }
        },
        sites: new class implements InstallationSiteProvider
        {
            public function installationSite(): InstallationSite
            {
                return new InstallationSite(1, 'Hotel de pruebas', 'Europe/Madrid');
            }
        },
        settings: new class implements OperationalSettingsProvider
        {
            public function forSite(int $siteId): OperationalSettings
            {
                return new OperationalSettings(
                    anomalousShiftMinutes: 720,
                    debounceSeconds: 60,
                    maximumClockSkewMinutes: 10,
                    minimumTransitSeconds: 120,
                    patternWindowSeconds: 10,
                    patternMinRepeats: 3,
                    breakClockingEnabled: false,
                    kioskUpdateWindow: KioskUpdateWindow::fromRange('03:00-05:00'),
                    kioskUpdateQuietMinutes: 10,
                    baselineManualHoursPerMonth: 0,
                    manualEntryFutureToleranceMinutes: 5,
                );
            }
        },
        compliance: new class implements CompliancePolicyProvider
        {
            public function forSite(int $siteId): CompliancePolicy
            {
                return new CompliancePolicy(
                    minimumRestMinutes: 720,
                    maximumDailyMinutes: 540,
                    breakRequiredAfterMinutes: 360,
                    retentionYears: 4,
                    maximumWeeklyMinutes: 2400,
                    weekStartsOn: 1,
                    holidayCalendar: [],
                );
            }
        },
        events: $events,
        anomalyMetrics: $anomalyMetrics,
        detectionMetrics: new class implements IncidentDetectionMetrics
        {
            public function scanCompleted(int $workDaysInspected, int $findings, int $failures, DateTimeImmutable $at): void {}
        },
        clock: FixedClock::at(REJECTED_PIN_DETECTION_NOW),
        logger: new NullLogger,
    );

    $result = $handler->handle(new DetectAnomaliesCommand($lookbackDays));

    $anomalies = [];

    foreach ($events->published as $event) {
        if ($event instanceof AttendanceAnomalyDetected && $event->anomaly->type === AnomalyType::REJECTED_PIN_SCAN) {
            $anomalies[] = $event->anomaly;
        }
    }

    return ['result' => $result, 'anomalies' => $anomalies, 'metrics' => $anomalyMetrics->byPattern, 'port' => $port];
}

it('agrupa cuatro intentos de la misma persona y dia en una incidencia con el primero', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-3', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:02:00'),
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00'),
        rejectedPinDetectionAttempt('0199-scan-4', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:03:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:01:00'),
    ]));

    expect($run['anomalies'])->toHaveCount(1);

    $anomaly = $run['anomalies'][0];

    expect($anomaly->employeeUuid)->toBe(REJECTED_PIN_DETECTION_ANA)
        ->and($anomaly->shiftEntryUuid)->toBeNull()
        ->and($anomaly->workDate->isoDate)->toBe('2026-03-14')
        ->and($anomaly->detectedAt)->toEqual(rejectedPinDetectionUtc(REJECTED_PIN_DETECTION_NOW))
        ->and($anomaly->context['scan_id'])->toBe('0199-scan-1')
        ->and($anomaly->context['occurred_at'])->toBe('2026-03-14T07:00:00.000000Z')
        ->and($anomaly->context['attempts'])->toBe(4);
})->group('RN-19', 'RF-PR-01');

it('no abre nada cuando un fichaje de la misma persona lo subsana a tiempo', function (): void {
    // Los tres resultados que subsanan (aceptado, anti-rebote, irreconciliable)
    // los elige la consulta; aqui llegan como instantes y lo que se prueba es
    // que un instante dentro de la ventana descarta el intento.
    $port = new InMemoryRejectedPinScans(
        [
            rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00'),
            rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-14 15:00:00'),
            rejectedPinDetectionAttempt('0199-scan-3', REJECTED_PIN_DETECTION_ANA, '2026-03-14 18:00:00'),
        ],
        [REJECTED_PIN_DETECTION_ANA => [
            rejectedPinDetectionUtc('2026-03-14 07:00:30'),
            rejectedPinDetectionUtc('2026-03-14 15:10:00'),
            rejectedPinDetectionUtc('2026-03-14 18:05:00'),
        ]],
    );

    $run = rejectedPinDetectionRun($port);

    expect($run['anomalies'])->toBe([])
        // La ventana que se pide al puerto es exactamente [intento, +600 s].
        ->and($port->recoveryQueries[0][1])->toEqual(rejectedPinDetectionUtc('2026-03-14 07:00:00'))
        ->and($port->recoveryQueries[0][2])->toEqual(rejectedPinDetectionUtc('2026-03-14 07:10:00'));
})->group('RN-19', 'RF-PR-01');

it('no cuenta como subsanado el fichaje de otra persona', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans(
        [rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00')],
        [REJECTED_PIN_DETECTION_BEA => [rejectedPinDetectionUtc('2026-03-14 07:00:30')]],
    ));

    expect($run['anomalies'])->toHaveCount(1);
})->group('RN-19');

it('abre una por persona', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_BEA, '2026-03-14 07:00:05'),
    ]));

    expect(array_map(static fn (DetectedAnomaly $a): string => $a->employeeUuid, $run['anomalies']))
        ->toBe([REJECTED_PIN_DETECTION_ANA, REJECTED_PIN_DETECTION_BEA]);
})->group('RN-19');

it('separa dos jornadas civiles del centro en el turno de noche', function (): void {
    // Madrid en marzo es UTC+1: 23:30 locales son 22:30 Z del dia 13 y 00:30
    // locales son 23:30 Z del mismo dia 13, pero ya jornada del 14 (RN-05).
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-13 22:30:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-13 23:30:00'),
    ]));

    expect(array_map(static fn (DetectedAnomaly $a): string => $a->workDate->isoDate, $run['anomalies']))
        ->toBe(['2026-03-13', '2026-03-14']);
})->group('RN-19', 'RN-05');

it('mira lo que llego en la ventana aunque ocurriera hace semanas', function (): void {
    // La cola drena tarde (misma razon que RN-18): se acota por recorded_at.
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-02-23 07:00:00', '2026-03-14 09:00:00'),
        // Y lo que llego antes de la ventana no entra, aunque sea reciente.
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_BEA, '2026-03-01 07:00:00', '2026-03-01 07:00:00'),
    ]));

    expect($run['anomalies'])->toHaveCount(1)
        ->and($run['anomalies'][0]->workDate->isoDate)->toBe('2026-02-23')
        ->and($run['anomalies'][0]->employeeUuid)->toBe(REJECTED_PIN_DETECTION_ANA);
})->group('RN-19', 'RF-PR-01');

it('cuenta los bloqueos y el mayor retraso de sincronizacion con signo', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00', '2026-03-14 09:00:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:01:00', '2026-03-14 09:00:01'),
        rejectedPinDetectionAttempt('0199-scan-3', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:02:00', '2026-03-14 09:03:00', lockout: true),
        rejectedPinDetectionAttempt('0199-scan-4', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:03:00', '2026-03-14 09:00:03', lockout: true),
        // Otra persona con el reloj del quiosco adelantado: retraso negativo.
        rejectedPinDetectionAttempt('0199-scan-5', REJECTED_PIN_DETECTION_BEA, '2026-03-14 07:01:30', '2026-03-14 07:00:00'),
    ]));

    [$ana, $bea] = $run['anomalies'];

    expect($ana->context['attempts'])->toBe(4)
        ->and($ana->context['lockout_attempts'])->toBe(2)
        ->and($ana->context['max_sync_delay_seconds'])->toBe(7260)
        ->and($bea->context['lockout_attempts'])->toBe(0)
        ->and($bea->context['max_sync_delay_seconds'])->toBe(-90)
        ->and(array_keys($ana->context))->toBe(['scan_id', 'occurred_at', 'attempts', 'lockout_attempts', 'max_sync_delay_seconds']);
})->group('RN-19', 'RS-12');

it('incluye rejected_pin_scan en el recuento por tipo y en la metrica', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_BEA, '2026-03-14 07:00:00'),
    ]));

    expect($run['result']->byType)->toBe(['rejected_pin_scan' => 2])
        ->and($run['metrics'])->toBe(['rejected_pin_scan' => 2]);
})->group('RN-19', 'RF-PR-01');
