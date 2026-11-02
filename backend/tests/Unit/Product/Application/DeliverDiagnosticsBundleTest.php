<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DeferredAuditReceipt;
use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Application\Port\StagedDiagnosticsBundle;
use App\Modules\Product\Application\UseCase\DeliverDiagnosticsBundle;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use Tests\Support\Product\RecordingDiagnosticsSealer;

/*
 * La entrega del paquete en el disco: temporal, pendiente y renombrado, en ese
 * orden y en un solo sitio (V4-PL-1; RF-PD-09, RL-04, RL-19; ADR-020, enmienda
 * del 14-10-2026).
 *
 * Con dobles del escritor y del almacen de pendientes que anotan cada llamada
 * en un mismo diario, para fijar el ORDEN, no solo que ocurrieron.
 */

/**
 * @return array{delivery: DeliverDiagnosticsBundle, log: ArrayObject<int, string>, receipts: ArrayObject<int, DeferredAuditReceipt|null>}
 */
function deliverDiagnosticsUnit(bool $saveFails = false, bool $publishFails = false): array
{
    /** @var ArrayObject<int, string> $log */
    $log = new ArrayObject;
    /** @var ArrayObject<int, DeferredAuditReceipt|null> $receipts */
    $receipts = new ArrayObject;

    $writer = new class($log, $receipts, $publishFails) implements DiagnosticsBundleWriter
    {
        /**
         * @param  ArrayObject<int, string>  $log
         * @param  ArrayObject<int, DeferredAuditReceipt|null>  $receipts
         */
        public function __construct(
            private readonly ArrayObject $log,
            private readonly ArrayObject $receipts,
            private readonly bool $publishFails,
        ) {}

        public function stage(DiagnosticsBundle|SealedDiagnosticsBundle $bundle, ?string $path = null): StagedDiagnosticsBundle
        {
            $this->log->append('stage:'.($path ?? 'default'));

            return new StagedDiagnosticsBundle(
                '/tmp/staging.json',
                '/tmp/final.json',
                $bundle->manifest->sha256,
                $bundle instanceof DiagnosticsBundle && $bundle->generationEntryPending(),
            );
        }

        public function publish(StagedDiagnosticsBundle $staged, ?DeferredAuditReceipt $receipt = null): string
        {
            $this->log->append('publish');
            $this->receipts->append($receipt);

            if ($this->publishFails) {
                throw new RuntimeException('rename');
            }

            return $staged->target;
        }

        public function discard(StagedDiagnosticsBundle $staged): void
        {
            $this->log->append('discard:'.$staged->temporary);
        }

        public function read(string $path): ?array
        {
            return null;
        }
    };

    $pending = new class($log, $saveFails) implements PendingDiagnosticsAudits
    {
        /** @param ArrayObject<int, string> $log */
        public function __construct(private readonly ArrayObject $log, private readonly bool $saveFails) {}

        public function save(PendingDiagnosticsAudit $pending): void
        {
            if ($this->saveFails) {
                $this->log->append('save-failed');

                throw DiagnosticsAuditNotDeferred::in('/sin/permiso');
            }

            $this->log->append('save:'.$pending->sha256);
        }

        public function all(): array
        {
            return [];
        }

        public function remove(string $sha256): void
        {
            $this->log->append('remove:'.$sha256);
        }
    };

    return ['delivery' => new DeliverDiagnosticsBundle($writer, $pending), 'log' => $log, 'receipts' => $receipts];
}

function deliverDiagnosticsUnitBundle(bool $pending): DiagnosticsBundle
{
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: ['installation' => ['v' => 1]],
        maxBytes: 8 * 1024 * 1024,
    );

    return $pending ? $bundle->withGenerationEntryPending('database_unavailable') : $bundle;
}

it('sin asiento pendiente: temporal y renombrado, sin pendiente ni comprobante', function (): void {
    ['delivery' => $delivery, 'log' => $log, 'receipts' => $receipts] = deliverDiagnosticsUnit();

    expect($delivery->handle(deliverDiagnosticsUnitBundle(false), '/salida'))->toBe('/tmp/final.json')
        ->and($log->getArrayCopy())->toBe(['stage:/salida', 'publish'])
        ->and($receipts->getArrayCopy())->toBe([null]);
})->group('RF-PD-09');

it('con asiento pendiente: temporal, pendiente guardado y renombrado con el comprobante de ese paquete', function (): void {
    ['delivery' => $delivery, 'log' => $log, 'receipts' => $receipts] = deliverDiagnosticsUnit();
    $bundle = deliverDiagnosticsUnitBundle(true);

    expect($delivery->handle($bundle))->toBe('/tmp/final.json')
        ->and($log->getArrayCopy())->toBe(['stage:default', 'save:'.$bundle->manifest->sha256, 'publish'])
        ->and($receipts[0]?->sha256)->toBe($bundle->manifest->sha256);
})->group('RF-PD-09', 'RL-04');

it('si el pendiente no se guarda, borra el temporal, no publica y sube el fallo', function (): void {
    ['delivery' => $delivery, 'log' => $log, 'receipts' => $receipts] = deliverDiagnosticsUnit(saveFails: true);

    expect(static fn () => $delivery->handle(deliverDiagnosticsUnitBundle(true)))->toThrow(DiagnosticsAuditNotDeferred::class);
    expect($log->getArrayCopy())->toBe(['stage:default', 'save-failed', 'discard:/tmp/staging.json'])
        ->and($receipts->getArrayCopy())->toBe([]);
})->group('RF-PD-09', 'RL-04', 'RL-19');

it('si el renombrado falla, borra el temporal y el pendiente, y sube el fallo', function (): void {
    ['delivery' => $delivery, 'log' => $log] = deliverDiagnosticsUnit(publishFails: true);
    $bundle = deliverDiagnosticsUnitBundle(true);

    expect(static fn () => $delivery->handle($bundle))->toThrow(RuntimeException::class, 'rename');
    expect($log->getArrayCopy())->toBe([
        'stage:default',
        'save:'.$bundle->manifest->sha256,
        'publish',
        'discard:/tmp/staging.json',
        'remove:'.$bundle->manifest->sha256,
    ]);
})->group('RF-PD-09', 'RL-04');

it('si el renombrado falla sin asiento pendiente, solo borra el temporal', function (): void {
    ['delivery' => $delivery, 'log' => $log] = deliverDiagnosticsUnit(publishFails: true);

    expect(static fn () => $delivery->handle(deliverDiagnosticsUnitBundle(false)))->toThrow(RuntimeException::class);
    expect($log->getArrayCopy())->toBe(['stage:default', 'publish', 'discard:/tmp/staging.json']);
})->group('RF-PD-09');

it('el paquete con datos personales llega sellado y se entrega sin asiento pendiente: nunca se aplaza', function (): void {
    // ADR-020, enmiendas del 14-10 y del 01-11-2026: solo el anonimizado puede
    // llegar con el asiento pendiente. El sobre lleva sus dos asientos ya
    // escritos, asi que la entrega es temporal y renombrado, sin pendiente.
    ['delivery' => $delivery, 'log' => $log, 'receipts' => $receipts] = deliverDiagnosticsUnit();
    $sealed = (new RecordingDiagnosticsSealer)->seal(DiagnosticsBundle::of(
        productVersion: '2.3.0',
        generatedAt: new DateTimeImmutable('2026-11-01T10:15:00Z'),
        anonymized: false,
        generatedBy: DiagnosticsActor::User,
        sections: ['installation' => ['v' => 1]],
        maxBytes: 8 * 1024 * 1024,
    ), 7);

    expect($delivery->handle($sealed, '/salida'))->toBe('/tmp/final.json')
        ->and($log->getArrayCopy())->toBe(['stage:/salida', 'publish'])
        ->and($receipts->getArrayCopy())->toBe([null]);
})->group('RF-PD-09', 'RL-19');
