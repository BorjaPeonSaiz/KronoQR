<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DeferredAuditReceipt;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Product\Infrastructure\Diagnostics\JsonDiagnosticsBundleWriter;

/*
 * El escritor del paquete en dos pasos (V4-PL-1; RF-PD-09, RL-04, RL-19;
 * ADR-020, enmienda del 14-10-2026): un paquete con el asiento pendiente no se
 * publica sin el comprobante de que SU pendiente esta guardado. La garantia la
 * da el tipo, no el orden en que llame nadie.
 */

beforeEach(function (): void {
    jsonDiagnosticsWriterDirectory(sys_get_temp_dir().'/kronoqr-writer-'.bin2hex(random_bytes(6)));
});

afterEach(function (): void {
    foreach (glob(jsonDiagnosticsWriterDirectory().'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir(jsonDiagnosticsWriterDirectory());
});

function jsonDiagnosticsWriterDirectory(?string $save = null): string
{
    static $directory = '';

    if ($save !== null) {
        $directory = $save;
    }

    return $directory;
}

function jsonDiagnosticsWriterBundle(bool $pending, int $value = 1): DiagnosticsBundle
{
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: ['installation' => ['v' => $value]],
        maxBytes: 8 * 1024 * 1024,
    );

    return $pending ? $bundle->withGenerationEntryPending('database_unavailable') : $bundle;
}

function jsonDiagnosticsWriterReceipt(DiagnosticsBundle $bundle): DeferredAuditReceipt
{
    return DeferredAuditReceipt::forSaved(PendingDiagnosticsAudit::of($bundle, 'database_unavailable'));
}

it('escribe un temporal 0600 con el nombre que barre la purga, y lo publica con el nombre del paquete', function (): void {
    $writer = new JsonDiagnosticsBundleWriter(jsonDiagnosticsWriterDirectory());
    $bundle = jsonDiagnosticsWriterBundle(false);

    $staged = $writer->stage($bundle);

    expect(basename($staged->temporary))->toMatch('/^kronoqr-diagnostics-staging-[0-9a-f]{12}\.json$/')
        ->and(substr(sprintf('%o', (int) fileperms($staged->temporary)), -3))->toBe('600')
        ->and($staged->target)->toBe(jsonDiagnosticsWriterDirectory().'/'.$bundle->manifest->fileName())
        ->and($staged->sha256)->toBe($bundle->manifest->sha256)
        ->and($staged->auditPending)->toBeFalse();

    $path = $writer->publish($staged);

    expect($path)->toBe(realpath($staged->target))
        ->and(is_file($staged->temporary))->toBeFalse()
        ->and(file_get_contents($path))->toBe($bundle->toJson());
})->group('RF-PD-09');

it('no publica un paquete pendiente sin comprobante, ni con el de otro paquete', function (?Closure $receipt): void {
    $writer = new JsonDiagnosticsBundleWriter(jsonDiagnosticsWriterDirectory());
    $bundle = jsonDiagnosticsWriterBundle(true);
    $staged = $writer->stage($bundle);
    /** @var DeferredAuditReceipt|null $comprobante */
    $comprobante = $receipt instanceof Closure ? $receipt() : null;

    expect($staged->auditPending)->toBeTrue()
        ->and(static fn () => $writer->publish($staged, $comprobante))->toThrow(LogicException::class);

    // Ni renombrado ni borrado: el temporal sigue ahi para que lo descarte quien llama.
    expect(is_file($staged->target))->toBeFalse()
        ->and(is_file($staged->temporary))->toBeTrue();
})->with([
    'sin comprobante' => [null],
    'con el de otro paquete' => [static fn (): DeferredAuditReceipt => jsonDiagnosticsWriterReceipt(jsonDiagnosticsWriterBundle(true, 2))],
])->group('RF-PD-09', 'RL-04', 'RL-19');

it('publica un paquete pendiente con el comprobante de su pendiente', function (): void {
    $writer = new JsonDiagnosticsBundleWriter(jsonDiagnosticsWriterDirectory());
    $bundle = jsonDiagnosticsWriterBundle(true);
    $staged = $writer->stage($bundle, jsonDiagnosticsWriterDirectory().'/a-mano.json');

    expect($writer->publish($staged, jsonDiagnosticsWriterReceipt($bundle)))->toBe(realpath(jsonDiagnosticsWriterDirectory().'/a-mano.json'));
})->group('RF-PD-09', 'RL-04');

it('descartar borra el temporal y nada mas', function (): void {
    $writer = new JsonDiagnosticsBundleWriter(jsonDiagnosticsWriterDirectory());
    $staged = $writer->stage(jsonDiagnosticsWriterBundle(false));

    $writer->discard($staged);
    $writer->discard($staged);

    expect(glob(jsonDiagnosticsWriterDirectory().'/*') ?: [])->toBe([]);
})->group('RF-PD-09');
