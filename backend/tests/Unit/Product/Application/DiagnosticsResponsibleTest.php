<?php

declare(strict_types=1);

use App\Modules\Product\Application\Exception\DiagnosticsResponsibleNotAllowed;
use App\Modules\Product\Application\Exception\DiagnosticsSealingUnavailable;
use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Product\RecordingDiagnosticsSealer;
use Tests\Support\Time\FixedClock;

/*
 * El paquete con datos personales pedido por consola lo firma una cuenta admin
 * activa, y sus dos asientos son de esa cuenta (2.3.0 bloque 3, PR17; RL-19,
 * doc 01 §7.2 «Firmas por consola»).
 *
 * Con dobles de los puertos: un publicador que guarda lo que publica y unas
 * cuentas en memoria que solo dan por buena la 7 con el rol admin.
 */

/** Lo que el caso de uso ha publicado, y en que orden con el candado. */
final class DiagnosticsResponsibleEvents
{
    /** @var list<DomainEvent> */
    public array $published = [];

    /** @var list<string> */
    public array $steps = [];

    public bool $chainHeld = false;

    /** Cuantas comprobaciones de la cuenta dan por buena a la 7 antes de su baja. */
    public int $activeFor = PHP_INT_MAX;
}

/**
 * @param  list<DiagnosticsCollector>  $collectors
 */
function diagnosticsResponsibleHandler(
    DiagnosticsResponsibleEvents $journal,
    ?RecordingDiagnosticsSealer $sealer = null,
    array $collectors = [],
    int $maxBytes = 8 * 1024 * 1024,
): GenerateDiagnosticsBundleHandler {
    return new GenerateDiagnosticsBundleHandler(
        collectors: $collectors,
        events: new readonly class($journal) implements ProductEventPublisher
        {
            public function __construct(private DiagnosticsResponsibleEvents $journal) {}

            public function publish(DomainEvent ...$events): void
            {
                $this->journal->steps[] = 'publish:'.($this->journal->chainHeld ? 'chain' : 'free');
                array_push($this->journal->published, ...$events);
            }
        },
        clock: FixedClock::at('2026-06-15 09:00:00'),
        productVersion: '2.3.0',
        maxBytes: $maxBytes,
        failures: new class implements ProbeFailureClassifier
        {
            public function unavailableService(Throwable $failure): ?string
            {
                return null;
            }
        },
        accounts: new readonly class($journal) implements ResponsibleAccounts
        {
            public function __construct(private DiagnosticsResponsibleEvents $journal) {}

            public function find(ResponsibleReference $reference): ?ResponsibleAccount
            {
                return null;
            }

            public function isActiveManagementAccount(int $userId, array $roles = []): bool
            {
                $this->journal->steps[] = 'responsible:'.($this->journal->chainHeld ? 'chain' : 'free');

                return $userId === 7 && $roles === [UserRole::ADMIN] && $this->journal->activeFor-- > 0;
            }
        },
        ledger: new readonly class($journal) implements SerializedLedgerWrite
        {
            public function __construct(private DiagnosticsResponsibleEvents $journal) {}

            public function withChainLock(callable $work): mixed
            {
                $this->journal->chainHeld = true;

                try {
                    return $work();
                } finally {
                    $this->journal->chainHeld = false;
                }
            }
        },
        sealer: $sealer ?? new RecordingDiagnosticsSealer,
    );
}

it('por consola, los datos personales sin cuenta que firme no se generan ni se publican', function (): void {
    $journal = new DiagnosticsResponsibleEvents;

    expect(fn (): mixed => diagnosticsResponsibleHandler($journal)->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::Console))
        ->toThrow(DiagnosticsResponsibleNotAllowed::class);

    expect($journal->published)->toBe([]);
})->group('RL-19', 'RL-04');

it('una cuenta que ya no es admin activa no firma: no se publica nada', function (): void {
    $journal = new DiagnosticsResponsibleEvents;

    expect(fn (): mixed => diagnosticsResponsibleHandler($journal)->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::Console, 8))
        ->toThrow(DiagnosticsResponsibleNotAllowed::class);

    expect($journal->published)->toBe([]);
})->group('RL-19', 'RL-04');

it('con la cuenta admin, los dos eventos llevan la cuenta que firma', function (): void {
    $journal = new DiagnosticsResponsibleEvents;

    $bundle = diagnosticsResponsibleHandler($journal)->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::Console, 7);

    expect($bundle->manifest->anonymized)->toBeFalse()
        // El manifiesto no nombra a nadie (ADR-020): la cuenta va solo al asiento.
        ->and($bundle->manifest->generatedBy)->toBe(DiagnosticsActor::Console)
        ->and($journal->published)->toHaveCount(2)
        ->and($journal->published[0])->toBeInstanceOf(DiagnosticsBundleGenerated::class)
        ->and($journal->published[1])->toBeInstanceOf(PersonalDataIncludedInDiagnostics::class);

    /** @var DiagnosticsBundleGenerated $generated */
    $generated = $journal->published[0];
    /** @var PersonalDataIncludedInDiagnostics $personal */
    $personal = $journal->published[1];

    expect($generated->responsibleUserId)->toBe(7)
        ->and($personal->responsibleUserId)->toBe(7);
})->group('RL-19', 'RL-04');

it('el anonimizado de consola y el del panel no piden cuenta', function (DiagnosticsActor $actor, DiagnosticsOptions $options): void {
    $journal = new DiagnosticsResponsibleEvents;

    diagnosticsResponsibleHandler($journal)->handle($options, $actor);

    /** @var DiagnosticsBundleGenerated $generated */
    $generated = $journal->published[0];

    expect($generated->responsibleUserId)->toBeNull();
})->with([
    'consola anonimizado' => [DiagnosticsActor::Console, DiagnosticsOptions::anonymized()],
    'panel con datos personales: firma la sesion' => [DiagnosticsActor::User, DiagnosticsOptions::withPersonalData(7)],
])->group('RL-19');

it('vuelve a comprobar la cuenta con la cadena tomada, y los asientos van dentro', function (): void {
    $journal = new DiagnosticsResponsibleEvents;

    diagnosticsResponsibleHandler($journal)->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::Console, 7);

    expect($journal->steps)->toBe(['responsible:free', 'responsible:chain', 'publish:chain']);
})->group('RL-19', 'RL-04');

it('una baja durante la generacion no deja asientos: la comprobacion con la cadena tomada la ve', function (): void {
    $journal = new DiagnosticsResponsibleEvents;
    // Activa al pedir el paquete; dada de baja cuando se va a firmar.
    $journal->activeFor = 1;

    expect(fn (): mixed => diagnosticsResponsibleHandler($journal)->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::Console, 7))
        ->toThrow(DiagnosticsResponsibleNotAllowed::class);

    expect($journal->published)->toBe([])
        ->and($journal->steps)->toBe(['responsible:free', 'responsible:chain']);
})->group('RL-19', 'RL-04');

/*
 * EL PAQUETE CON DATOS PERSONALES SALE CIFRADO O NO SALE (ADR-020, enmienda del
 * 01-11-2026; RL-19, RF-PD-09).
 */

/** Un recolector que apunta si se le ha llamado y devuelve las filas que se le den. */
final class DiagnosticsSealingCollector implements DiagnosticsCollector
{
    public int $calls = 0;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(private readonly string $name, private readonly array $payload) {}

    public function section(): string
    {
        return $this->name;
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $this->calls++;

        return $this->payload;
    }
}

/**
 * Una seccion `personal_data` con `$rows` fichas de unos 200 bytes.
 *
 * @return array<string, mixed>
 */
function diagnosticsSealingPersonalData(int $rows): array
{
    $items = [];

    for ($i = 0; $i < $rows; $i++) {
        $items[] = ['uuid' => sprintf('0190a1b2-c3d4-7e5f-8a9b-%012d', $i), 'employee_code' => 'E'.$i, 'full_name' => str_repeat('Nombre ', 20)];
    }

    return [
        'period_days' => 7,
        'since' => '2026-06-08T09:00:00Z',
        'employees' => ['total' => $rows, 'truncated' => false, 'items' => $items],
        'shift_entries' => ['total' => 0, 'truncated' => false, 'items' => []],
        'scan_events' => ['total' => 0, 'truncated' => false, 'items' => []],
        'incidents' => ['total' => 0, 'truncated' => false, 'items' => []],
    ];
}

it('con datos personales y sin clave de soporte, lanza antes de recolectar y no publica nada', function (DiagnosticsActor $actor, ?int $responsible): void {
    $journal = new DiagnosticsResponsibleEvents;
    $sealer = new RecordingDiagnosticsSealer(available: false);
    $collector = new DiagnosticsSealingCollector('personal_data', diagnosticsSealingPersonalData(1));

    expect(fn (): mixed => diagnosticsResponsibleHandler($journal, $sealer, [$collector])
        ->handle(DiagnosticsOptions::withPersonalData(7), $actor, $responsible))
        ->toThrow(DiagnosticsSealingUnavailable::class);

    expect($collector->calls)->toBe(0)
        ->and($sealer->sealed)->toBe(0)
        ->and($journal->published)->toBe([])
        // Ni siquiera se pregunta por la cuenta: sin clave no hay nada que firmar.
        ->and($journal->steps)->toBe([]);
})->with([
    'desde el panel' => [DiagnosticsActor::User, null],
    'por consola, con cuenta' => [DiagnosticsActor::Console, 7],
])->group('RL-19', 'RF-PD-09');

it('el anonimizado nunca pasa por el sellador, ni pregunta por la clave', function (DiagnosticsActor $actor): void {
    $journal = new DiagnosticsResponsibleEvents;
    $sealer = new RecordingDiagnosticsSealer(available: false);

    $bundle = diagnosticsResponsibleHandler($journal, $sealer)->handle(DiagnosticsOptions::anonymized(), $actor);

    expect($bundle)->toBeInstanceOf(DiagnosticsBundle::class)
        ->and($bundle->manifest->anonymized)->toBeTrue()
        ->and($sealer->sealed)->toBe(0)
        ->and($sealer->asked)->toBe(0);

    /** @var DiagnosticsBundleGenerated $generated */
    $generated = $journal->published[0];

    expect($generated->sealed)->toBeFalse()
        ->and($generated->recipientKeyId)->toBeNull()
        ->and($generated->sealedSha256)->toBeNull();
})->with([
    'panel' => [DiagnosticsActor::User],
    'consola' => [DiagnosticsActor::Console],
    'soporte' => [DiagnosticsActor::SupportGrant],
])->group('RL-19', 'RF-PD-09');

it('con datos personales devuelve el sobre, y los dos eventos llevan su clave y la huella de los bytes entregados', function (): void {
    $journal = new DiagnosticsResponsibleEvents;
    $sealer = new RecordingDiagnosticsSealer;
    $collector = new DiagnosticsSealingCollector('personal_data', diagnosticsSealingPersonalData(3));

    $sealed = diagnosticsResponsibleHandler($journal, $sealer, [$collector])
        ->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::User);

    expect($sealed)->toBeInstanceOf(SealedDiagnosticsBundle::class);
    assert($sealed instanceof SealedDiagnosticsBundle);

    /** @var DiagnosticsBundleGenerated $generated */
    $generated = $journal->published[0];
    /** @var PersonalDataIncludedInDiagnostics $personal */
    $personal = $journal->published[1];
    $delivered = hash('sha256', $sealed->toJson());

    expect($sealer->sealed)->toBe(1)
        ->and($sealed->contents['status'])->toBe('included')
        ->and($sealed->contents['collections']['employees'] ?? null)->toBe(['rows' => 3, 'columns' => ['uuid', 'employee_code', 'full_name']])
        ->and($generated->sealed)->toBeTrue()
        ->and($generated->anonymized)->toBeFalse()
        ->and($generated->recipientKeyId)->toBe(RecordingDiagnosticsSealer::KEY_ID)
        ->and($generated->sealedSha256)->toBe($delivered)
        ->and($generated->sizeBytes)->toBe(\strlen($sealed->toJson()))
        // `sha256` sigue siendo la huella del texto en claro, la del manifiesto.
        ->and($generated->sha256)->toBe($sealed->manifest->sha256)
        ->and($personal->sealed)->toBeTrue()
        ->and($personal->recipientKeyId)->toBe(RecordingDiagnosticsSealer::KEY_ID)
        ->and($personal->sealedSha256)->toBe($delivered)
        ->and($personal->sha256)->toBe($sealed->manifest->sha256)
        ->and($personal->collections)->toBe(['employees', 'shift_entries', 'scan_events', 'incidents']);
})->group('RL-19', 'RF-PD-09');

it('el sobre cabe en el tope: si no, se renuncia a los datos personales y la cabecera lo dice', function (): void {
    $journal = new DiagnosticsResponsibleEvents;
    $max = 64 * 1024;
    // Unas 900 fichas de ~200 bytes: en claro ya pasan del tope, y en base64 mas.
    $collector = new DiagnosticsSealingCollector('personal_data', diagnosticsSealingPersonalData(900));

    $sealed = diagnosticsResponsibleHandler($journal, new RecordingDiagnosticsSealer, [$collector], $max)
        ->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::User);

    assert($sealed instanceof SealedDiagnosticsBundle);

    expect(\strlen($sealed->toJson()))->toBeLessThanOrEqual($max)
        ->and($sealed->contents['status'])->toBe('omitted')
        ->and($sealed->contents)->not->toHaveKey('collections');
})->group('RL-19', 'RF-PD-09');

it('un sobre que cabe de entrada no se recorta', function (): void {
    $journal = new DiagnosticsResponsibleEvents;
    $collector = new DiagnosticsSealingCollector('personal_data', diagnosticsSealingPersonalData(20));

    $sealed = diagnosticsResponsibleHandler($journal, new RecordingDiagnosticsSealer, [$collector], 64 * 1024)
        ->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::User);

    assert($sealed instanceof SealedDiagnosticsBundle);

    expect($sealed->contents['status'])->toBe('included')
        ->and($sealed->contents['collections']['employees']['rows'] ?? null)->toBe(20);
})->group('RL-19', 'RF-PD-09');
