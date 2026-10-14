<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Tests\Support\Time\FixedClock;

/*
 * Cuando se aplaza el asiento del paquete y cuando NO (V4-PL-1; RL-04, RL-19;
 * ADR-020, enmienda del 14-10-2026).
 *
 * Con dobles de los tres puertos: un publicador que lanza como lo haria el
 * listener con la base caida, un clasificador que dice que servicio cayo y un
 * almacen de pendientes que cuenta lo que se le guarda. Lo que se fija es la
 * regla de las tres condiciones: consola, anonimizado y base no disponible. Con
 * cualquier otra combinacion, el fallo sube tal cual y no se guarda nada.
 */

/**
 * @return array{handler: GenerateDiagnosticsBundleHandler, pending: ArrayObject<int, PendingDiagnosticsAudit>}
 */
function diagnosticsDeferralHandler(?string $service, ?Throwable $failure = null): array
{
    /** @var ArrayObject<int, PendingDiagnosticsAudit> $saved */
    $saved = new ArrayObject;

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

    $pending = new class($saved) implements PendingDiagnosticsAudits
    {
        /** @param ArrayObject<int, PendingDiagnosticsAudit> $saved */
        public function __construct(private readonly ArrayObject $saved) {}

        public function save(PendingDiagnosticsAudit $pending): void
        {
            $this->saved->append($pending);
        }

        public function all(): array
        {
            return array_values($this->saved->getArrayCopy());
        }

        public function remove(string $sha256): void {}
    };

    return [
        'handler' => new GenerateDiagnosticsBundleHandler(
            collectors: [],
            events: $events,
            clock: FixedClock::at('2026-06-15 09:00:00'),
            productVersion: '2.2.1',
            maxBytes: 8 * 1024 * 1024,
            failures: $failures,
            pending: $pending,
        ),
        'pending' => $saved,
    ];
}

it('consola, anonimizado y base caida: devuelve el paquete con la constancia y no guarda nada hasta deferAudit', function (): void {
    ['handler' => $handler, 'pending' => $saved] = diagnosticsDeferralHandler(ProbeFailureClassifier::DATABASE);

    $bundle = $handler->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::Console);

    expect($bundle->generationEntryPending())->toBeTrue()
        ->and($saved)->toHaveCount(0);

    $handler->deferAudit($bundle);

    $first = $saved->getArrayCopy()[0] ?? null;

    expect($saved)->toHaveCount(1)
        ->and($first?->sha256)->toBe($bundle->manifest->sha256)
        ->and($first?->reason)->toBe('database_unavailable');
})->group('RF-PD-09', 'RL-04');

it('no aplaza fuera de las tres condiciones: relanza el fallo original y no guarda nada', function (DiagnosticsActor $actor, DiagnosticsOptions $options, ?string $service): void {
    $failure = new RuntimeException('el fallo original');
    ['handler' => $handler, 'pending' => $saved] = diagnosticsDeferralHandler($service, $failure);

    expect(static fn () => $handler->handle($options, $actor))->toThrow($failure);
    expect($saved)->toHaveCount(0);
})->with([
    'desde el panel, base caida' => [DiagnosticsActor::User, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::DATABASE],
    'por soporte, base caida' => [DiagnosticsActor::SupportGrant, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::DATABASE],
    'consola con datos personales, base caida' => [DiagnosticsActor::Console, DiagnosticsOptions::withPersonalData(7), ProbeFailureClassifier::DATABASE],
    'consola, Redis caido' => [DiagnosticsActor::Console, DiagnosticsOptions::anonymized(), ProbeFailureClassifier::REDIS],
    'consola, fallo del producto' => [DiagnosticsActor::Console, DiagnosticsOptions::anonymized(), null],
])->group('RF-PD-09', 'RL-04', 'RL-19');

it('deferAudit rechaza un paquete que no lleva el asiento pendiente', function (): void {
    ['handler' => $handler, 'pending' => $saved] = diagnosticsDeferralHandler(ProbeFailureClassifier::DATABASE);
    $bundle = $handler->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::Console);

    $sinConstancia = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: $bundle->manifest->generatedAt,
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: [],
        maxBytes: 1024,
    );

    expect(static fn () => $handler->deferAudit($sinConstancia))->toThrow(LogicException::class);
    expect($saved)->toHaveCount(0);
})->group('RF-PD-09', 'RL-04');
