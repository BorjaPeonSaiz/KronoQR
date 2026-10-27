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
use App\Modules\Attendance\Domain\Event\RejectedPinScanRecovered;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\RecoveringScan;
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
use Tests\Support\Shared\RecordingCaughtFailures;
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

    /** @var list<array{string, DateTimeImmutable, DateTimeImmutable}> */
    public array $workDayQueries = [];

    /**
     * Cada fichaje que subsana lleva un `scan_id` con su hora, para leer las
     * aserciones sin tabla de correspondencias.
     *
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

    public function rejectedOf(string $claimantUuid, DateTimeImmutable $fromOccurredAt, DateTimeImmutable $toOccurredAt): array
    {
        $this->workDayQueries[] = [$claimantUuid, $fromOccurredAt, $toOccurredAt];

        $ofWorkDay = array_values(array_filter(
            $this->attempts,
            static fn (RejectedPinAttempt $a): bool => $a->claimantUuid === $claimantUuid && $a->occurredAt >= $fromOccurredAt && $a->occurredAt < $toOccurredAt,
        ));

        usort($ofWorkDay, static fn (RejectedPinAttempt $a, RejectedPinAttempt $b): int => [$a->occurredAt, $a->scanId] <=> [$b->occurredAt, $b->scanId]);

        return $ofWorkDay;
    }

    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->recoveryQueries[] = [$employeeUuid, $from, $to];

        $inWindow = array_values(array_filter(
            $this->recovering[$employeeUuid] ?? [],
            static fn (DateTimeImmutable $at): bool => $at >= $from && $at <= $to,
        ));

        return array_map(static fn (DateTimeImmutable $at): RecoveringScan => new RecoveringScan('recovering '.$at->format('H:i:s'), $at), $inWindow);
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
 * @return array{result: AnomalyScanResult, anomalies: list<DetectedAnomaly>, recovered: list<RejectedPinScanRecovered>, metrics: array<string, int>, detection: array{findings: int, failures: int, recoveredWorkDays: int}|null, port: InMemoryRejectedPinScans}
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

    $detectionMetrics = new class implements IncidentDetectionMetrics
    {
        /** @var array{findings: int, failures: int, recoveredWorkDays: int}|null */
        public ?array $published = null;

        public function scanCompleted(int $workDaysInspected, int $findings, int $failures, int $recoveredWorkDays, DateTimeImmutable $at): void
        {
            $this->published = ['findings' => $findings, 'failures' => $failures, 'recoveredWorkDays' => $recoveredWorkDays];
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

            public function previousShiftEndBefore(string $employeeUuid, DateTimeImmutable $instant): ?DateTimeImmutable
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

            public function unattributedServerDiscardsBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
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
                    personnelRecordRetentionYears: 4,
                );
            }
        },
        events: $events,
        anomalyMetrics: $anomalyMetrics,
        detectionMetrics: $detectionMetrics,
        clock: FixedClock::at(REJECTED_PIN_DETECTION_NOW),
        logger: new NullLogger,
        caughtFailures: new RecordingCaughtFailures,
    );

    $result = $handler->handle(new DetectAnomaliesCommand($lookbackDays));

    $anomalies = [];
    $recovered = [];

    foreach ($events->published as $event) {
        if ($event instanceof AttendanceAnomalyDetected && $event->anomaly->type === AnomalyType::REJECTED_PIN_SCAN) {
            $anomalies[] = $event->anomaly;
        }

        if ($event instanceof RejectedPinScanRecovered) {
            $recovered[] = $event;
        }
    }

    return ['result' => $result, 'anomalies' => $anomalies, 'recovered' => $recovered, 'metrics' => $anomalyMetrics->byPattern, 'detection' => $detectionMetrics->published, 'port' => $port];
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

    expect(array_map(static fn (DetectedAnomaly $a): ?string => $a->employeeUuid, $run['anomalies']))
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

// --- R3-AR-03 / R3-BE-02 · la ventana abierta y la subsanacion que llega tarde ---

it('no abre nada mientras la ventana de 600 s del intento siga abierta', function (): void {
    // El reloj esta a las 12:00:00: un intento de las 11:55 todavia puede
    // subsanarse hasta las 12:05. Lo vera la pasada siguiente.
    $port = new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-15 11:55:00'),
    ]);

    $run = rejectedPinDetectionRun($port);

    expect($run['anomalies'])->toBe([])
        ->and($run['recovered'])->toBe([])
        ->and($run['result']->byType)->toBe([]);
})->group('RN-19', 'RF-PR-01');

it('abre la incidencia con los intentos sin subsanar aunque otro de la jornada espere su ventana', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans([
        rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-15 07:00:00'),
        rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-15 11:58:00', lockout: true),
    ]));

    expect($run['anomalies'])->toHaveCount(1)
        ->and($run['anomalies'][0]->context['scan_id'])->toBe('0199-scan-1')
        ->and($run['anomalies'][0]->context['attempts'])->toBe(1)
        ->and($run['anomalies'][0]->context['lockout_attempts'])->toBe(0)
        ->and($run['recovered'])->toBe([]);
})->group('RN-19', 'RF-PR-01');

it('cuenta en la incidencia solo los intentos sin subsanar, y el primero de ellos', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans(
        [
            rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00', '2026-03-14 09:00:00', lockout: true),
            rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-14 15:00:00', '2026-03-14 15:00:10'),
        ],
        [REJECTED_PIN_DETECTION_ANA => [rejectedPinDetectionUtc('2026-03-14 07:05:00')]],
    ));

    expect($run['anomalies'])->toHaveCount(1)
        ->and($run['anomalies'][0]->context['scan_id'])->toBe('0199-scan-2')
        ->and($run['anomalies'][0]->context['occurred_at'])->toBe('2026-03-14T15:00:00.000000Z')
        ->and($run['anomalies'][0]->context['attempts'])->toBe(1)
        ->and($run['anomalies'][0]->context['lockout_attempts'])->toBe(0)
        ->and($run['anomalies'][0]->context['max_sync_delay_seconds'])->toBe(10)
        ->and($run['recovered'])->toBe([]);
})->group('RN-19', 'RF-PR-01');

it('anuncia la jornada subsanada entera para que se cierre su incidencia', function (): void {
    // El caso de R3-BE-02: PIN erroneo a las 07:02 y el bueno a las 07:05 en
    // otro quiosco sin red. Cuando el bueno llega, la jornada esta subsanada.
    $port = new InMemoryRejectedPinScans(
        [
            rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:02:00'),
            rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:03:00'),
        ],
        [REJECTED_PIN_DETECTION_ANA => [rejectedPinDetectionUtc('2026-03-14 07:05:00')]],
    );

    $run = rejectedPinDetectionRun($port);

    expect($run['anomalies'])->toBe([])
        ->and($run['recovered'])->toHaveCount(1)
        ->and($run['recovered'][0]->employeeUuid())->toBe(REJECTED_PIN_DETECTION_ANA)
        ->and($run['recovered'][0]->workDate())->toBe('2026-03-14')
        ->and($run['recovered'][0]->siteId())->toBe(1)
        ->and($run['recovered'][0]->occurredAt())->toEqual(rejectedPinDetectionUtc(REJECTED_PIN_DETECTION_NOW))
        // El asiento del cierre nombra los dos intentos y el fichaje que
        // subsana a ambos, una sola vez.
        ->and($run['recovered'][0]->attemptScanIds())->toBe(['0199-scan-1', '0199-scan-2'])
        ->and($run['recovered'][0]->recoveringScanIds())->toBe(['recovering 07:05:00'])
        // La jornada entera se pide por sus limites civiles en Madrid (UTC+1).
        ->and($port->workDayQueries)->toEqual([[
            REJECTED_PIN_DETECTION_ANA,
            rejectedPinDetectionUtc('2026-03-13 23:00:00'),
            rejectedPinDetectionUtc('2026-03-14 23:00:00'),
        ]])
        // Ni el recuento ni la metrica cuentan un cierre como hallazgo...
        ->and($run['result']->byType)->toBe([])
        // ...pero la pasada si cuenta la jornada que anuncio.
        ->and($run['result']->recoveredWorkDays)->toBe(1)
        ->and($run['detection'])->toBe(['findings' => 0, 'failures' => 0, 'recoveredWorkDays' => 1]);
})->group('RN-19', 'RF-PR-01');

it('no anuncia la jornada si un intento suyo sin subsanar quedo fuera de la ventana de recepcion', function (): void {
    // Ventana de 7 dias: desde el 08-03 a las 12:00. El intento de las 08:00 del
    // 08-03 llego antes y no lo ve `rejectedBetween()`; el de las 13:00 si, y
    // esta subsanado. La jornada entera no lo esta: la incidencia no se cierra.
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans(
        [
            rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-08 08:00:00'),
            rejectedPinDetectionAttempt('0199-scan-2', REJECTED_PIN_DETECTION_ANA, '2026-03-08 13:00:00'),
        ],
        [REJECTED_PIN_DETECTION_ANA => [rejectedPinDetectionUtc('2026-03-08 13:01:00')]],
    ));

    expect($run['recovered'])->toBe([])
        ->and($run['anomalies'])->toBe([])
        ->and($run['result']->recoveredWorkDays)->toBe(0);
})->group('RN-19', 'RF-PR-01');

it('no anuncia nada de una persona cuyo fichaje es de otra', function (): void {
    $run = rejectedPinDetectionRun(new InMemoryRejectedPinScans(
        [rejectedPinDetectionAttempt('0199-scan-1', REJECTED_PIN_DETECTION_ANA, '2026-03-14 07:00:00')],
        [REJECTED_PIN_DETECTION_BEA => [rejectedPinDetectionUtc('2026-03-14 07:00:30')]],
    ));

    expect($run['recovered'])->toBe([]);
})->group('RN-19');
