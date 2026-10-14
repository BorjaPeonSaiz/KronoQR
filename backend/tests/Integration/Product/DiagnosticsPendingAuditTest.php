<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\PendingDiagnosticsAuditProbe;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;

/*
 * **El asiento aplazado se escribe solo cuando la base vuelve** (V4-PL-1;
 * RL-04, RL-19, regla dura 6; ADR-020, enmienda del 14-10-2026).
 *
 * Lo que se comprueba es lo que hace que el aplazamiento no sea una perdida:
 * que el asiento llega a existir con la huella del paquete; que `occurred_at`
 * es el instante en que se escribe y no el de la generacion —la cadena y el
 * sellado anual suponen `occurred_at` creciente (ADR-010, ADR-027)— y la
 * generacion queda en el payload; que vaciar dos veces deja una fila; que la
 * cadena sigue verificando; y que `doctor` avisa si un pendiente envejece.
 */

uses(RefreshDatabase::class);

const DIAGNOSTICS_PENDING_AUDIT_GENERATED_AT = '2026-06-15 09:00:00';

beforeEach(function (): void {
    FrozenTime::at(DIAGNOSTICS_PENDING_AUDIT_GENERATED_AT);

    $directory = sys_get_temp_dir().'/kronoqr-diagnostics-pending-'.bin2hex(random_bytes(6));
    diagnosticsPendingAuditDirectory($directory);
    Config::set('product.diagnostics_pending_audit_path', $directory);
});

afterEach(function (): void {
    foreach (glob(diagnosticsPendingAuditDirectory().'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir(diagnosticsPendingAuditDirectory());
});

function diagnosticsPendingAuditDirectory(?string $save = null): string
{
    static $directory = '';

    if ($save !== null) {
        $directory = $save;
    }

    return $directory;
}

/** El pendiente que deja un paquete generado ahora con la base caida. */
function diagnosticsPendingAuditSaved(): PendingDiagnosticsAudit
{
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: app(Clock::class)->now(),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: [
            'installation' => ['product_version' => '2.2.1'],
            'audit' => ['status' => 'unavailable', 'reason' => 'App\Support\Database\DatabaseUnavailable'],
        ],
        maxBytes: 8 * 1024 * 1024,
    )->withGenerationEntryPending(PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);

    $pending = PendingDiagnosticsAudit::of($bundle, PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);
    app(PendingDiagnosticsAudits::class)->save($pending);

    return $pending;
}

/** @return list<object{occurred_at: string, payload: string}> */
function diagnosticsPendingAuditRows(): array
{
    /** @var list<object{occurred_at: string, payload: string}> */
    return DB::table('audit_log')
        ->where('action', 'diagnostics.bundle_generated')
        ->get(['occurred_at', 'payload'])
        ->all();
}

it('al volver la base escribe el asiento con la huella, deferred y la generacion en el payload, y occurred_at del vaciado (V4-PL-1)', function (): void {
    $pending = diagnosticsPendingAuditSaved();

    FrozenTime::at('2026-06-15 10:30:00');

    expect(Artisan::call('product:diagnostics:record-pending'))->toBe(0);

    $rows = diagnosticsPendingAuditRows();
    expect($rows)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode($rows[0]->payload, true, 512, JSON_THROW_ON_ERROR);
    $occurredAt = (new DateTimeImmutable($rows[0]->occurred_at))->setTimezone(new DateTimeZone('UTC'));

    expect($payload['sha256'])->toBe($pending->sha256)
        ->and($payload['deferred'])->toBeTrue()
        ->and($payload['generated_at'])->toBe('2026-06-15T09:00:00.000000Z')
        ->and($payload['anonymized'])->toBeTrue()
        ->and($payload['generated_by'])->toBe('console')
        ->and($occurredAt->format('Y-m-d H:i:s'))->toBe('2026-06-15 10:30:00')
        // El pendiente se borra tras el asiento.
        ->and(glob(diagnosticsPendingAuditDirectory().'/*') ?: [])->toBe([]);
})->group('RF-PD-09', 'RL-04', 'RL-19');

it('vaciar dos veces, aunque el pendiente reaparezca, deja un solo asiento, y la cadena sigue verificando (V4-PL-1)', function (): void {
    $pending = diagnosticsPendingAuditSaved();

    FrozenTime::at('2026-06-15 10:30:00');
    expect(Artisan::call('product:diagnostics:record-pending'))->toBe(0);

    // El proceso murio entre el asiento y el borrado: el pendiente sigue ahi.
    app(PendingDiagnosticsAudits::class)->save($pending);

    FrozenTime::at('2026-06-15 10:35:00');
    expect(Artisan::call('product:diagnostics:record-pending'))->toBe(0);

    expect(diagnosticsPendingAuditRows())->toHaveCount(1)
        ->and(glob(diagnosticsPendingAuditDirectory().'/*') ?: [])->toBe([])
        ->and(Artisan::call('compliance:verify-audit-chain'))->toBe(0);
})->group('RF-PD-09', 'RL-04');

it('product:diagnostics escribe al arrancar los asientos pendientes de generaciones anteriores (V4-PL-1)', function (): void {
    $pending = diagnosticsPendingAuditSaved();
    Config::set('product.diagnostics_storage_path', diagnosticsPendingAuditDirectory().'/bundles');

    FrozenTime::at('2026-06-15 10:30:00');
    Artisan::call('product:diagnostics', ['--no-interaction' => true]);

    $payloads = array_map(
        static fn (object $row): mixed => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
        diagnosticsPendingAuditRows(),
    );

    expect(array_column($payloads, 'sha256'))->toContain($pending->sha256)
        ->and(Artisan::output())->toContain('asiento(s) pendiente(s)');

    foreach (glob(diagnosticsPendingAuditDirectory().'/bundles/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir(diagnosticsPendingAuditDirectory().'/bundles');
})->group('RF-PD-09', 'RL-04');

it('un pendiente alterado no se convierte en asiento (V4-PL-1)', function (): void {
    $pending = diagnosticsPendingAuditSaved();
    $file = diagnosticsPendingAuditDirectory().'/'.$pending->sha256.'.json';

    /** @var array<string, mixed> $data */
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($file, json_encode([...$data, 'note' => 'texto libre'], JSON_THROW_ON_ERROR));

    expect(app(PendingDiagnosticsAudits::class)->all())->toBe([]);

    file_put_contents($file, json_encode([...$data, 'anonymized' => false], JSON_THROW_ON_ERROR));

    expect(app(PendingDiagnosticsAudits::class)->all())->toBe([]);
})->group('RF-PD-09', 'RL-19');

it('doctor avisa si un asiento lleva mas de una hora pendiente, y lo dice en los dos idiomas (V4-PL-1)', function (): void {
    diagnosticsPendingAuditSaved();
    $probe = new PendingDiagnosticsAuditProbe(app(PendingDiagnosticsAudits::class), app(Clock::class));

    FrozenTime::at('2026-06-15 09:59:00');
    expect($probe->run()[0]->status)->toBe(DoctorStatus::Ok);

    FrozenTime::at('2026-06-15 10:00:00');
    $probe = new PendingDiagnosticsAuditProbe(app(PendingDiagnosticsAudits::class), app(Clock::class));
    $finding = $probe->run()[0];

    expect($finding->id)->toBe('diagnostics.pending_audit')
        ->and($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->details)->toMatchArray(['pending' => 1, 'stale' => 1]);

    $translator = app(DoctorTranslator::class);

    foreach (['es', 'en'] as $locale) {
        expect($translator->translate($finding->messageKey(), $finding->params, $locale))->toBeString()->toContain('1')
            ->and($translator->translate((string) $finding->fixKey(), $finding->params, $locale))
            ->toContain('php artisan product:diagnostics:record-pending');
    }
})->group('RF-PD-13', 'RL-04');
