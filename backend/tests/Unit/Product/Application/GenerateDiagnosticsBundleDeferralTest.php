<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
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
 * Cuando se aplaza el asiento del paquete y cuando NO (V4-PL-1; RL-04, RL-19;
 * ADR-020, enmienda del 14-10-2026).
 *
 * Con dobles de los puertos: un publicador que lanza como lo haria el listener
 * con la base caida y un clasificador que dice que servicio cayo. Lo que se fija
 * es la regla de las tres condiciones: consola, anonimizado y base no
 * disponible. Con cualquier otra combinacion, el fallo original sube tal cual.
 * Guardar el pendiente no es cosa de este caso de uso: lo hace
 * `DeliverDiagnosticsBundle` al entregar el fichero.
 */

function diagnosticsDeferralHandler(?string $service, ?Throwable $failure = null): GenerateDiagnosticsBundleHandler
{
    $events = new class($failure ?? new RuntimeException('caida')) implements ProductEventPublisher
    {
        public function __construct(private readonly Throwable $failure) {}

        public function publish(DomainEvent ...$events): void
        {
            throw $this->failure;
        }
    };

    $failures = new class($service) implements ProbeFailureClassifier
    {
        public function __construct(private readonly ?string $service) {}

        public function unavailableService(Throwable $failure): ?string
        {
            /** @var self::REDIS|self::DATABASE|null */
            return $this->service;
        }
    };

    return new GenerateDiagnosticsBundleHandler(
        collectors: [],
        events: $events,
        clock: FixedClock::at('2026-06-15 09:00:00'),
        productVersion: '2.2.1',
        maxBytes: 8 * 1024 * 1024,
        failures: $failures,
        accounts: new class implements ResponsibleAccounts
        {
            public function find(ResponsibleReference $reference): ?ResponsibleAccount
            {
                return null;
            }

            public function isActiveManagementAccount(int $userId, array $roles = []): bool
            {
                // Solo una cuenta admin firma el paquete con datos personales.
                return $roles === [UserRole::ADMIN];
            }
        },
        ledger: new class implements SerializedLedgerWrite
        {
            public function withChainLock(callable $work): mixed
            {
                return $work();
            }
        },
    );
}

it('consola, anonimizado y base caida: devuelve el paquete con la constancia de asiento pendiente', function (): void {
    $bundle = diagnosticsDeferralHandler(ProbeFailureClassifier::DATABASE)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::Console);

    /** @var array<string, mixed> $audit */
    $audit = $bundle->sections['audit'] ?? [];

    expect($bundle->generationEntryPending())->toBeTrue()
        ->and($audit['generation_entry'])->toBe(['status' => 'pending', 'reason' => 'database_unavailable'])
        ->and($bundle->manifest->anonymized)->toBeTrue();
})->group('RF-PD-09', 'RL-04');

it('no aplaza fuera de las tres condiciones: relanza el fallo original', function (DiagnosticsActor $actor, DiagnosticsOptions $options, ?string $service): void {
    $failure = new RuntimeException('el fallo original');
    $handler = diagnosticsDeferralHandler($service, $failure);

    // Por consola, el paquete con datos personales lo firma una cuenta admin
    // (2.3.0 bloque 3): sin ella el caso de uso ni siquiera llega al asiento.
    $responsible = $actor === DiagnosticsActor::Console && $options->includePersonalData ? 7 : null;

    expect(static fn () => $handler->handle($options, $actor, $responsible))->toThrow($failure);
})->with([
    'desde el panel, base caida' => [DiagnosticsActor::User, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::DATABASE],
    'por soporte, base caida' => [DiagnosticsActor::SupportGrant, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::DATABASE],
    'consola con datos personales, base caida' => [DiagnosticsActor::Console, DiagnosticsOptions::withPersonalData(7), ProbeFailureClassifier::DATABASE],
    'consola, Redis caido' => [DiagnosticsActor::Console, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::REDIS],
    'consola, fallo del producto' => [DiagnosticsActor::Console, DiagnosticsOptions::anonymized(), null],
])->group('RF-PD-09', 'RL-04', 'RL-19');
