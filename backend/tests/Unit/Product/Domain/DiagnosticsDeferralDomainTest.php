<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\Exception\ProductDomainException;

/*
 * Las dos piezas de dominio del asiento aplazado del paquete de diagnostico
 * (V4-PL-1; RL-04; ADR-020, enmienda del 14-10-2026): el evento que se vuelve a
 * publicar al volver la base y la excepcion que impide entregar el paquete si
 * el pendiente no se puede guardar.
 */

it('el evento normal no esta aplazado ni lleva instante de generacion', function (): void {
    $now = new DateTimeImmutable('2026-06-15T09:00:00Z');
    $event = new DiagnosticsBundleGenerated(
        anonymized: true,
        sections: ['installation'],
        sha256: str_repeat('a', 64),
        sizeBytes: 10,
        generatedBy: 'console',
        occurredAt: $now,
    );

    expect($event->deferred)->toBeFalse()
        ->and($event->generatedAt)->toBeNull()
        ->and($event->occurredAt())->toBe($now)
        ->and($event->eventName())->toBe('product.diagnostics_bundle_generated');
})->group('RF-PD-09', 'RL-04');

it('el evento aplazado lleva el instante de la generacion aparte del de la escritura', function (): void {
    $generated = new DateTimeImmutable('2026-06-15T09:00:00Z');
    $written = new DateTimeImmutable('2026-06-15T10:30:00Z');
    $event = new DiagnosticsBundleGenerated(
        anonymized: true,
        sections: ['installation', 'audit'],
        sha256: str_repeat('b', 64),
        sizeBytes: 20,
        generatedBy: 'console',
        occurredAt: $written,
        deferred: true,
        generatedAt: $generated,
    );

    expect($event->deferred)->toBeTrue()
        ->and($event->generatedAt)->toBe($generated)
        ->and($event->occurredAt())->toBe($written)
        ->and($event->sections)->toBe(['installation', 'audit'])
        ->and($event->sizeBytes)->toBe(20);
})->group('RF-PD-09', 'RL-04');

it('la excepcion del pendiente dice el directorio y que hacer, y es del dominio del producto', function (): void {
    $failure = DiagnosticsAuditNotDeferred::in('/var/www/html/storage/app/diagnostics-audit-pending');

    expect($failure)->toBeInstanceOf(ProductDomainException::class)
        ->and($failure->getMessage())->toBe(
            'No se pudo escribir el asiento pendiente del paquete en /var/www/html/storage/app/diagnostics-audit-pending'
            .'. Comprueba que storage esta montado y que el usuario de la aplicacion puede escribir en el'
            .' directorio (php artisan product:doctor).'
        )
        ->and($failure->getPrevious())->toBeNull();
})->group('RF-PD-09', 'RL-04');
