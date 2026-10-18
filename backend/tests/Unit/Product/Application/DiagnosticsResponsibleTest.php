<?php

declare(strict_types=1);

use App\Modules\Product\Application\Exception\DiagnosticsResponsibleNotAllowed;
use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;
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

function diagnosticsResponsibleHandler(DiagnosticsResponsibleEvents $journal): GenerateDiagnosticsBundleHandler
{
    return new GenerateDiagnosticsBundleHandler(
        collectors: [],
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
        maxBytes: 8 * 1024 * 1024,
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
