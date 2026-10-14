<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;

/*
 * El asiento pendiente de un paquete generado con la base caida (V4-PL-1;
 * RL-04, RL-19; ADR-020, enmienda del 14-10-2026).
 *
 * Se escribe en el disco del cliente y se relee para convertirlo en un asiento
 * del registro de auditoria: lo que se relee tiene que tener exactamente la
 * forma que se escribio, o no entra en el trail.
 */

function pendingDiagnosticsAuditUnit(): PendingDiagnosticsAudit
{
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00.123456Z'),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: ['installation' => ['v' => 1], 'audit' => ['status' => 'unavailable']],
        maxBytes: 8 * 1024 * 1024,
    )->withGenerationEntryPending(PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);

    return PendingDiagnosticsAudit::of($bundle, PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);
}

it('toma del paquete la huella, las secciones, el tamaño, el actor y el instante', function (): void {
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: ['installation' => ['v' => 1]],
        maxBytes: 8 * 1024 * 1024,
    );
    $pending = PendingDiagnosticsAudit::of($bundle, 'database_unavailable');

    expect($pending->sha256)->toBe($bundle->manifest->sha256)
        ->and($pending->sections)->toBe(['installation'])
        ->and($pending->sizeBytes)->toBe(strlen($bundle->toJson()))
        ->and($pending->anonymized)->toBeTrue()
        ->and($pending->generatedBy)->toBe('console')
        ->and($pending->generatedAt)->toBe($bundle->manifest->generatedAt)
        ->and($pending->reason)->toBe('database_unavailable');
})->group('RF-PD-09', 'RL-04');

it('escribe exactamente las siete claves cerradas, con el instante en UTC', function (): void {
    $data = pendingDiagnosticsAuditUnit()->toArray();

    expect(array_keys($data))->toBe(PendingDiagnosticsAudit::KEYS)
        ->and(PendingDiagnosticsAudit::KEYS)->toBe(['sha256', 'sections', 'size_bytes', 'anonymized', 'generated_by', 'generated_at', 'reason'])
        ->and($data['generated_at'])->toBe('2026-06-15T09:00:00.123456Z')
        ->and($data['reason'])->toBe('database_unavailable')
        ->and($data['sections'])->toBe(['installation', 'audit']);
})->group('RF-PD-09', 'RL-04');

it('ida y vuelta: lo que se escribe se relee igual', function (): void {
    $pending = pendingDiagnosticsAuditUnit();
    $read = PendingDiagnosticsAudit::fromArray($pending->toArray());

    expect($read)->not->toBeNull()
        ->and($read?->toArray())->toBe($pending->toArray())
        ->and($read?->generatedAt->format('U.u'))->toBe($pending->generatedAt->format('U.u'));
})->group('RF-PD-09', 'RL-04');

it('rechaza lo que no tiene exactamente la forma escrita', function (Closure $alter): void {
    expect(PendingDiagnosticsAudit::fromArray($alter(pendingDiagnosticsAuditUnit()->toArray())))->toBeNull();
})->with([
    'una clave de mas' => [static fn (array $d): array => [...$d, 'note' => 'texto libre']],
    'una clave de menos' => [static function (array $d): array {
        unset($d['reason']);

        return $d;
    }],
    'claves en otro orden' => [static fn (array $d): array => ['sections' => $d['sections'], ...$d]],
    'huella corta' => [static fn (array $d): array => [...$d, 'sha256' => 'abc']],
    'huella en mayusculas' => [static fn (array $d): array => [...$d, 'sha256' => strtoupper((string) $d['sha256'])]],
    'huella que no es texto' => [static fn (array $d): array => [...$d, 'sha256' => 12]],
    'secciones que no son lista' => [static fn (array $d): array => [...$d, 'sections' => ['a' => 'installation']]],
    'secciones que no son array' => [static fn (array $d): array => [...$d, 'sections' => 'installation']],
    'una seccion inventada' => [static fn (array $d): array => [...$d, 'sections' => ['installation', 'contrasenas']]],
    'una seccion que no es texto' => [static fn (array $d): array => [...$d, 'sections' => ['installation', 3]]],
    // `true == 'installation'` en PHP: sin comparacion estricta pasaria.
    'una seccion booleana' => [static fn (array $d): array => [...$d, 'sections' => ['installation', true]]],
    'tamaño negativo' => [static fn (array $d): array => [...$d, 'size_bytes' => -1]],
    'tamaño que no es entero' => [static fn (array $d): array => [...$d, 'size_bytes' => '10']],
    'anonymized false' => [static fn (array $d): array => [...$d, 'anonymized' => false]],
    'anonymized como texto' => [static fn (array $d): array => [...$d, 'anonymized' => 'true']],
    'generado por el panel' => [static fn (array $d): array => [...$d, 'generated_by' => 'user']],
    'generado por soporte' => [static fn (array $d): array => [...$d, 'generated_by' => 'support_grant']],
    'generated_at fuera de formato' => [static fn (array $d): array => [...$d, 'generated_at' => '2026-06-15 09:00:00']],
    'generated_at relativo' => [static fn (array $d): array => [...$d, 'generated_at' => 'now']],
    'generated_at que no es texto' => [static fn (array $d): array => [...$d, 'generated_at' => 1_750_000_000]],
    'otro motivo' => [static fn (array $d): array => [...$d, 'reason' => 'redis_unavailable']],
])->group('RF-PD-09', 'RL-04', 'RL-19');

it('admite tamaño cero y todas las secciones del contrato', function (): void {
    $data = [...pendingDiagnosticsAuditUnit()->toArray(), 'size_bytes' => 0, 'sections' => DiagnosticsBundle::SECTIONS];

    expect(PendingDiagnosticsAudit::fromArray($data)?->sizeBytes)->toBe(0)
        ->and(PendingDiagnosticsAudit::fromArray([...$data, 'sections' => []])?->sections)->toBe([]);
})->group('RF-PD-09');
