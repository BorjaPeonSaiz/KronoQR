<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;

/*
 * Los dos eventos del paquete con datos personales.
 *
 * Su nombre es contrato: es la accion que escribe `audit_log` (RS-07) y la que
 * compara `product:diagnostics --verify` con el sobre. Desde la enmienda de
 * ADR-020 del 01-11-2026 llevan si el paquete salio cifrado, para que clave y
 * la huella de los bytes entregados, y `sealed` no tiene valor por defecto: un
 * asiento no puede declararse en claro por omision. El anonimizado y el
 * aplazado los cubre `DiagnosticsDeferralDomainTest`.
 */

it('anota en la generacion del paquete cifrado la clave y la huella del sobre', function (): void {
    $event = new DiagnosticsBundleGenerated(
        anonymized: false,
        sections: ['manifest', 'personal_data'],
        sha256: str_repeat('a', 64),
        sizeBytes: 2048,
        generatedBy: 'panel',
        occurredAt: new DateTimeImmutable('2026-11-03T08:00:00+00:00'),
        deferred: false,
        sealed: true,
        recipientKeyId: '0123456789abcdef',
        sealedSha256: str_repeat('d', 64),
    );

    expect($event->eventName())->toBe('product.diagnostics_bundle_generated')
        ->and($event->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-11-03T08:00:00+00:00')
        ->and($event->sealed)->toBeTrue()
        ->and($event->recipientKeyId)->toBe('0123456789abcdef')
        ->and($event->sealedSha256)->toBe(str_repeat('d', 64));
})->group('RF-PD-09', 'RL-19');

it('nombra la inclusion de datos personales con el nombre estable de la auditoria y su instante', function (): void {
    $event = new PersonalDataIncludedInDiagnostics(
        periodDays: 7,
        collections: ['employees', 'shift_entries'],
        sha256: str_repeat('b', 64),
        generatedBy: 'panel',
        occurredAt: new DateTimeImmutable('2026-11-03T09:30:00+00:00'),
        sealed: true,
        recipientKeyId: '0123456789abcdef',
        sealedSha256: str_repeat('c', 64),
    );

    expect($event->eventName())->toBe('product.personal_data_included_in_diagnostics')
        ->and($event->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-11-03T09:30:00+00:00')
        ->and($event->sealed)->toBeTrue()
        ->and($event->recipientKeyId)->toBe('0123456789abcdef')
        ->and($event->sealedSha256)->toBe(str_repeat('c', 64));
})->group('RF-PD-09', 'RL-19');
