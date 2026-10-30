<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\Port\CardFingerprintOwners;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\DiscardedScanReportLog;
use App\Modules\Attendance\Application\Port\DiscardedScanReportRecord;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\RecordedScan;
use App\Modules\Attendance\Application\Port\RecordedScanFailures;
use App\Modules\Attendance\Application\Port\ScanDiscardSettings;
use App\Modules\Attendance\Application\Port\ScanFailureAttempt;
use App\Modules\Attendance\Application\Port\ScanFailureClassifier;
use App\Modules\Attendance\Application\Port\ScanFailureLedger;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Application\UseCase\GuardedScan;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use App\Modules\Attendance\Application\UseCase\ScanBatchOutcome;
use App\Modules\Attendance\Application\UseCase\ScanRegistration;
use App\Modules\Attendance\Application\UseCase\UnprocessableScanGuard;
use App\Modules\Attendance\Domain\Event\UnprocessableScanDiscarded;
use App\Modules\Attendance\Domain\Support\PayloadFingerprint;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Shared\RecordingCaughtFailures;
use Tests\Support\Time\FixedClock;

/*
 * **El guardian del descarte, sin base de datos** (RN-22, ADR-061; doc 02
 * §9.5: la regla, unitaria). La politica la prueba
 * `UnprocessableScanPolicyTest`; aqui se prueba que el guardian la aplica en el
 * orden correcto y que, ante cualquier duda, **conserva**: relanza la MISMA
 * excepcion del caso de uso.
 */

const GUARDIAN_SCAN_ID = '0199f0c2-0000-7000-8000-0000000000a1';

const GUARDIAN_DEVICE = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

const GUARDIAN_EMPLOYEE = '0199f0c2-1f4a-7c3e-9b21-000000000001';

const GUARDIAN_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/** Libro de fallos en memoria que devuelve la historia que se le diga. */
final class LibroDeFallosDePrueba implements ScanFailureLedger
{
    /** @var list<ScanFailureAttempt> */
    public array $apuntes = [];

    public function __construct(
        private readonly ?RecordedScanFailures $historia,
        private readonly int $descartes = 0,
        private readonly bool $falla = false,
    ) {}

    public function record(ScanFailureAttempt $attempt): ?RecordedScanFailures
    {
        if ($this->falla) {
            throw new RuntimeException('No se puede escribir el apunte.');
        }

        $this->apuntes[] = $attempt;

        return $this->historia;
    }

    public function serverDiscardsSince(int $deviceId, DateTimeImmutable $since): int
    {
        return $this->descartes;
    }

    public function purgeFailedBefore(DateTimeImmutable $cutoff): int
    {
        return 0;
    }
}

/** Registro de escaneos en memoria. */
final class RegistroDeEscaneosDelGuardian implements ScanLog
{
    /** @var list<ScanRecord> */
    public array $filas = [];

    public function __construct(private ?RecordedScan $existente = null) {}

    public function record(ScanRecord $scan): bool
    {
        if ($this->existente instanceof RecordedScan) {
            return false;
        }

        $this->filas[] = $scan;
        $this->existente = new RecordedScan($scan->scanId, $scan->employeeUuid, $scan->occurredAt, $scan->recordedAt, $scan->result, null, null);

        return true;
    }

    public function find(string $scanId): ?RecordedScan
    {
        return $this->existente;
    }

    public function acceptedScansAdjacentTo(string $employeeUuid, DateTimeImmutable $instant): array
    {
        return [];
    }

    public function lastAcceptedScanOf(string $employeeUuid): ?AcceptedScan
    {
        return null;
    }
}

/** Avisos en memoria. */
final class AvisosDelGuardian implements DiscardedScanReportLog
{
    /** @var list<DiscardedScanReportRecord> */
    public array $avisos = [];

    public function record(DiscardedScanReportRecord $report): bool
    {
        $this->avisos[] = $report;

        return true;
    }
}

/** Publicador en memoria. */
final class EventosDelGuardian implements EventPublisher
{
    /** @var list<DomainEvent> */
    public array $publicados = [];

    public function publish(DomainEvent ...$events): void
    {
        array_push($this->publicados, ...$events);
    }
}

/**
 * @param  array{clasifica?: bool, libro?: LibroDeFallosDePrueba, escaneos?: RegistroDeEscaneosDelGuardian, umbral?: ScanDiscardThreshold, resolutor?: CredentialResolver, metricas?: RecordingScanMetrics, avisos?: AvisosDelGuardian, eventos?: EventosDelGuardian, huella?: string|null}  $piezas
 */
function guardianDePrueba(array $piezas = []): UnprocessableScanGuard
{
    /** @var ConnectionInterface&MockInterface $conexion */
    $conexion = Mockery::mock(ConnectionInterface::class);
    $conexion->shouldReceive('transaction')->andReturnUsing(static fn (Closure $cuerpo): mixed => $cuerpo());

    $clasifica = $piezas['clasifica'] ?? true;
    $umbral = $piezas['umbral'] ?? new ScanDiscardThreshold(10, 60, 3);

    return new UnprocessableScanGuard(
        classifier: new class($clasifica) implements ScanFailureClassifier
        {
            public function __construct(private readonly bool $cuenta) {}

            public function counts(Throwable $failure): bool
            {
                return $this->cuenta;
            }
        },
        ledger: $piezas['libro'] ?? new LibroDeFallosDePrueba(historiaDeFallos(10, 61)),
        settings: new class($umbral) implements ScanDiscardSettings
        {
            public function __construct(private readonly ScanDiscardThreshold $umbral) {}

            public function threshold(): ScanDiscardThreshold
            {
                return $this->umbral;
            }
        },
        scans: $piezas['escaneos'] ?? new RegistroDeEscaneosDelGuardian,
        reports: $piezas['avisos'] ?? new AvisosDelGuardian,
        credentials: $piezas['resolutor'] ?? new class implements CredentialResolver
        {
            public function resolve(string $qrPayload): CredentialResolution
            {
                return CredentialResolution::resolved(GUARDIAN_EMPLOYEE, new DateTimeImmutable('2026-01-01T00:00:00Z'));
            }
        },
        employees: new class implements EmployeeDirectory
        {
            public function find(string $employeeUuid): ?EmployeeSnapshot
            {
                return null;
            }

            public function findWithEmploymentPeriods(string $employeeUuid): ?EmployeeSnapshot
            {
                return null;
            }

            public function findByCode(string $employeeCode): ?EmployeeSnapshot
            {
                return null;
            }
        },
        events: $piezas['eventos'] ?? new EventosDelGuardian,
        metrics: $piezas['metricas'] ?? new RecordingScanMetrics,
        clock: FixedClock::at('2026-08-14 09:00:00'),
        connection: $conexion,
        logger: new NullLogger,
        floor: new ConstantTimeFloor(0),
        fingerprints: new class($piezas['huella'] ?? null) implements CardFingerprintOwners
        {
            public function __construct(private readonly ?string $dueno) {}

            public function latestAcceptedOwner(string $payloadFingerprint, DateTimeImmutable $notBefore, DateTimeImmutable $notAfter): ?string
            {
                return $this->dueno;
            }
        },
    );
}

function historiaDeFallos(int $intentos, int $minutosDesdeElPrimero): RecordedScanFailures
{
    return new RecordedScanFailures(
        new ScanFailureHistory(
            $intentos,
            (new DateTimeImmutable('2026-08-14 09:00:00', new DateTimeZone('UTC')))->modify('-'.$minutosDesdeElPrimero.' minutes'),
        ),
        [LogicException::class],
    );
}

function escaneoGuardado(): GuardedScan
{
    return GuardedScan::ofScan(new RegisterScanCommand(
        scanId: GUARDIAN_SCAN_ID,
        qrPayload: GUARDIAN_PAYLOAD,
        occurredAt: new DateTimeImmutable('2026-08-14 07:00:00', new DateTimeZone('UTC')),
        deviceId: 7,
        deviceUuid: GUARDIAN_DEVICE,
        origin: ScanOrigin::QR_KIOSK,
        intent: ScanIntent::AUTO,
    ));
}

/**
 * Lo que lanza el guardian, o el resultado si no lanza.
 */
function intentarConVeneno(UnprocessableScanGuard $guardian, Throwable $veneno): Throwable|RegisterScanResult
{
    try {
        return $guardian->register(escaneoGuardado(), static fn (): RegisterScanResult => throw $veneno);
    } catch (Throwable $lanzada) {
        return $lanzada;
    }
}

it('no hace nada en el camino feliz', function (): void {
    $libro = new LibroDeFallosDePrueba(historiaDeFallos(10, 61));
    $esperado = RegisterScanResult::rejected(GUARDIAN_SCAN_ID, ScanResult::REJECTED_REVOKED, new DateTimeImmutable('2026-08-14T07:00:00Z'), new DateTimeImmutable('2026-08-14T07:00:00Z'));

    $resultado = guardianDePrueba(['libro' => $libro])->register(escaneoGuardado(), static fn (): RegisterScanResult => $esperado);

    expect($resultado)->toBe($esperado)
        ->and($libro->apuntes)->toBe([]);
})->group('RN-22', 'RNF-P-01');

it('relanza la misma excepcion sin apuntar nada si el fallo no cuenta', function (): void {
    $libro = new LibroDeFallosDePrueba(historiaDeFallos(10, 61));
    $veneno = new LogicException('dependencia caida');

    expect(intentarConVeneno(guardianDePrueba(['clasifica' => false, 'libro' => $libro]), $veneno))->toBe($veneno)
        ->and($libro->apuntes)->toBe([]);
})->group('RN-22', 'RNF-D-03');

it('apunta el intento con la clase y sin mensaje y conserva dentro de la ventana', function (): void {
    $libro = new LibroDeFallosDePrueba(historiaDeFallos(10, 59));
    $veneno = new LogicException('mensaje con datos que no se guardan');

    expect(intentarConVeneno(guardianDePrueba(['libro' => $libro]), $veneno))->toBe($veneno)
        ->and($libro->apuntes)->toHaveCount(1)
        ->and($libro->apuntes[0]->exceptionClass)->toBe(LogicException::class)
        ->and($libro->apuntes[0]->payloadFingerprint)->toBe(hash('sha256', GUARDIAN_PAYLOAD))
        ->and($libro->apuntes[0]->deviceId)->toBe(7);
})->group('RN-22');

it('conserva si el intento no coincide con el primero', function (): void {
    $veneno = new LogicException('veneno');

    expect(intentarConVeneno(guardianDePrueba(['libro' => new LibroDeFallosDePrueba(null)]), $veneno))->toBe($veneno);
})->group('RN-22', 'RS-03');

it('conserva y relanza la original si el apunte no se puede escribir', function (): void {
    $veneno = new LogicException('veneno');

    expect(intentarConVeneno(guardianDePrueba(['libro' => new LibroDeFallosDePrueba(null, falla: true)]), $veneno))->toBe($veneno);
})->group('RN-22');

it('conserva y avisa con el cupo agotado', function (): void {
    $metricas = new RecordingScanMetrics;
    $veneno = new LogicException('veneno');

    $resultado = intentarConVeneno(guardianDePrueba([
        'libro' => new LibroDeFallosDePrueba(historiaDeFallos(10, 61), descartes: 3),
        'metricas' => $metricas,
    ]), $veneno);

    expect($resultado)->toBe($veneno)
        ->and($metricas->budgetExhausted)->toBe([GUARDIAN_DEVICE])
        ->and($metricas->serverDiscarded)->toBe([]);
})->group('RN-22');

it('descarta con fila, aviso del servidor, evento y metrica al cumplir el umbral', function (): void {
    $escaneos = new RegistroDeEscaneosDelGuardian;
    $avisos = new AvisosDelGuardian;
    $eventos = new EventosDelGuardian;
    $metricas = new RecordingScanMetrics;

    $resultado = intentarConVeneno(guardianDePrueba([
        'escaneos' => $escaneos,
        'avisos' => $avisos,
        'eventos' => $eventos,
        'metricas' => $metricas,
    ]), new LogicException('veneno'));

    expect($resultado)->toBeInstanceOf(RegisterScanResult::class);

    /** @var RegisterScanResult $resultado */
    expect($resultado->isDiscarded())->toBeTrue()
        ->and($resultado->isRejected())->toBeFalse()
        ->and($resultado->isAccepted())->toBeFalse()
        ->and($escaneos->filas)->toHaveCount(1)
        ->and($escaneos->filas[0]->result)->toBe(ScanResult::DISCARDED_UNPROCESSABLE)
        ->and($escaneos->filas[0]->shiftEntryUuid)->toBeNull()
        ->and($escaneos->filas[0]->workedMinutes)->toBeNull()
        ->and($escaneos->filas[0]->clientMeta)->toBe([])
        ->and($escaneos->filas[0]->employeeUuid)->toBe(GUARDIAN_EMPLOYEE)
        ->and($avisos->avisos[0]->source)->toBe(DiscardedScanReportRecord::SOURCE_SERVER)
        ->and($avisos->avisos[0]->httpStatus)->toBe(422)
        ->and($avisos->avisos[0]->problemType)->toBe('urn:kronoqr:problem:scan-discarded')
        ->and($avisos->avisos[0]->failedAttempts)->toBe(10)
        ->and($eventos->publicados)->toHaveCount(1)
        ->and($eventos->publicados[0])->toBeInstanceOf(UnprocessableScanDiscarded::class)
        ->and($metricas->serverDiscarded)->toBe([['device' => GUARDIAN_DEVICE, 'attributed' => true]]);
})->group('RN-22', 'RF-AT-09');

it('descarta sin atribuir si la atribucion lanza', function (): void {
    $avisos = new AvisosDelGuardian;
    $metricas = new RecordingScanMetrics;

    $resultado = intentarConVeneno(guardianDePrueba([
        'avisos' => $avisos,
        'metricas' => $metricas,
        'resolutor' => new class implements CredentialResolver
        {
            public function resolve(string $qrPayload): CredentialResolution
            {
                throw new RuntimeException('el resolutor era lo roto');
            }
        },
    ]), new LogicException('veneno'));

    expect($resultado)->toBeInstanceOf(RegisterScanResult::class)
        ->and($avisos->avisos[0]->attribution->isAttributed())->toBeFalse()
        ->and($metricas->serverDiscarded)->toBe([['device' => GUARDIAN_DEVICE, 'attributed' => false]]);
})->group('RN-22');

it('repite el descarte ya escrito sin apuntar otro intento', function (): void {
    $libro = new LibroDeFallosDePrueba(historiaDeFallos(10, 61));
    $escaneos = new RegistroDeEscaneosDelGuardian(new RecordedScan(
        GUARDIAN_SCAN_ID,
        null,
        new DateTimeImmutable('2026-08-14T07:00:00Z'),
        new DateTimeImmutable('2026-08-14T08:30:00Z'),
        ScanResult::DISCARDED_UNPROCESSABLE,
        null,
        null,
    ));

    $resultado = intentarConVeneno(guardianDePrueba(['libro' => $libro, 'escaneos' => $escaneos]), new LogicException('veneno'));

    expect($resultado)->toBeInstanceOf(RegisterScanResult::class)
        ->and($libro->apuntes)->toBe([]);

    /** @var RegisterScanResult $resultado */
    expect($resultado->isReplay)->toBeTrue()
        ->and($resultado->isDiscarded())->toBeTrue();
})->group('RN-22', 'RF-AT-07');

it('no descarta encima de una fila que no es un descarte', function (): void {
    $veneno = new LogicException('veneno');
    $escaneos = new RegistroDeEscaneosDelGuardian(new RecordedScan(
        GUARDIAN_SCAN_ID,
        GUARDIAN_EMPLOYEE,
        new DateTimeImmutable('2026-08-14T07:00:00Z'),
        new DateTimeImmutable('2026-08-14T08:30:00Z'),
        ScanResult::CLOCK_IN,
        '2026-08-14',
        0,
    ));

    expect(intentarConVeneno(guardianDePrueba(['escaneos' => $escaneos]), $veneno))->toBe($veneno);
})->group('RN-22', 'RF-AT-07');

// --- El lote con la cabeza descartada sigue procesando ------------------------

/** Registro de lote cuyo primer elemento sale descartado por el guardian. */
final class RegistroConCabezaDescartada implements ScanRegistration
{
    /** @var list<string> */
    public array $llamadas = [];

    public function handle(RegisterScanCommand $command): RegisterScanResult
    {
        $this->llamadas[] = $command->scanId;

        if ($this->llamadas === [$command->scanId]) {
            return RegisterScanResult::discarded($command->scanId, $command->occurredAt, $command->occurredAt);
        }

        return RegisterScanResult::accepted(
            scanId: $command->scanId,
            result: ScanResult::CLOCK_IN,
            occurredAt: $command->occurredAt,
            recordedAt: $command->occurredAt,
            employeeUuid: GUARDIAN_EMPLOYEE,
            employeeDisplayName: 'Maria G.',
            workDate: '2026-08-14',
            workedMinutes: 0,
        );
    }
}

it('procesa en el lote lo que va detras de un elemento descartado', function (): void {
    $registro = new RegistroConCabezaDescartada;
    $metricas = new RecordingScanMetrics;

    $comandos = array_map(static fn (string $id, string $hora): RegisterScanCommand => new RegisterScanCommand(
        scanId: $id,
        qrPayload: GUARDIAN_PAYLOAD,
        occurredAt: new DateTimeImmutable($hora, new DateTimeZone('UTC')),
        deviceId: 7,
        deviceUuid: GUARDIAN_DEVICE,
    ), ['0199f0c2-0000-7000-8000-0000000000b1', '0199f0c2-0000-7000-8000-0000000000b2', '0199f0c2-0000-7000-8000-0000000000b3'], ['2026-08-14T07:00:00Z', '2026-08-14T08:00:00Z', '2026-08-14T09:00:00Z']);

    $desenlaces = (new RegisterScanBatchHandler($registro, $metricas, FixedClock::at('2026-08-14 18:00:00'), new NullLogger, new RecordingCaughtFailures))
        ->handle(ScanBatch::of($comandos));

    expect(array_map(static fn (ScanBatchOutcome $o): bool => $o->wasProcessed(), $desenlaces))->toBe([true, true, true])
        ->and($desenlaces[0]->result?->isDiscarded())->toBeTrue()
        ->and($registro->llamadas)->toHaveCount(3)
        // Un descarte no es un no procesado: no suma a la alerta del lote.
        ->and($metricas->notProcessed)->toBe([]);
})->group('RN-22', 'RN-21', 'RF-KI-04');

// --- A2: la huella del contenido, y nadie en scan_events salvo por tarjeta ----

it('atribuye por la huella una tarjeta que no se atribuye por su firma, sin poner persona en scan_events', function (bool $resolutorLanza): void {
    $escaneos = new RegistroDeEscaneosDelGuardian;
    $avisos = new AvisosDelGuardian;
    $eventos = new EventosDelGuardian;

    $resultado = intentarConVeneno(guardianDePrueba([
        'escaneos' => $escaneos,
        'avisos' => $avisos,
        'eventos' => $eventos,
        'huella' => GUARDIAN_EMPLOYEE,
        'resolutor' => new class($resolutorLanza) implements CredentialResolver
        {
            public function __construct(private readonly bool $lanza) {}

            public function resolve(string $qrPayload): CredentialResolution
            {
                if ($this->lanza) {
                    throw new RuntimeException('el resolutor era lo roto');
                }

                return CredentialResolution::rejected(CredentialRejectionReason::INVALID_SIGNATURE);
            }
        },
    ]), new LogicException('veneno'));

    /** @var UnprocessableScanDiscarded $evento */
    $evento = $eventos->publicados[0];

    expect($resultado)->toBeInstanceOf(RegisterScanResult::class)
        ->and($avisos->avisos[0]->attribution->method)->toBe(DiscardedScanAttributionMethod::FINGERPRINT_MATCH)
        ->and($avisos->avisos[0]->attribution->ownerUuid)->toBe(GUARDIAN_EMPLOYEE)
        ->and($escaneos->filas[0]->employeeUuid)->toBeNull()
        ->and($evento->attribution)->toBe(DiscardedScanAttributionMethod::FINGERPRINT_MATCH);
})->with(['el resolutor no atribuye' => [false], 'el resolutor lanza' => [true]])->group('RN-22');

it('deja el descarte sin atribuir si la huella tampoco aparece', function (): void {
    $avisos = new AvisosDelGuardian;

    intentarConVeneno(guardianDePrueba([
        'avisos' => $avisos,
        'resolutor' => new class implements CredentialResolver
        {
            public function resolve(string $qrPayload): CredentialResolution
            {
                return CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN);
            }
        },
    ]), new LogicException('veneno'));

    expect($avisos->avisos[0]->attribution->method)->toBe(DiscardedScanAttributionMethod::NONE);
})->group('RN-22');

it('calcula la misma huella que el registro del fichaje, de una sola fuente', function (): void {
    // ADR-061 A2: la atribucion por huella busca en `scan_events` lo que calcula
    // el guardian. Si discreparan, no encontraria a nadie.
    $clase = new ReflectionClass(RegisterScanHandler::class);
    $delRegistro = $clase->getMethod('fingerprintOf')->invoke($clase->newInstanceWithoutConstructor(), GUARDIAN_PAYLOAD);

    expect(escaneoGuardado()->contentFingerprint)->toBe(PayloadFingerprint::ofQrPayload(GUARDIAN_PAYLOAD))
        ->and(escaneoGuardado()->scanEventFingerprint())->toBe(escaneoGuardado()->contentFingerprint)
        ->and(PayloadFingerprint::ofQrPayload(GUARDIAN_PAYLOAD))->toBe(hash('sha256', GUARDIAN_PAYLOAD))
        ->and($delRegistro)->toBe(escaneoGuardado()->contentFingerprint);
})->group('RN-22', 'RS-03');
