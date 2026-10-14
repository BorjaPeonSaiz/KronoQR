<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Listener\RecordDiagnosticsBundleGenerated;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la base arriba, un asiento que falla sigue cerrando el paquete**
 * (V4-PL-1; RL-04, RL-19, regla dura 6; ADR-020, enmienda del 14-10-2026).
 *
 * El aplazamiento del asiento es solo para «la base no esta disponible». Un
 * listener que revienta, una particion que falta o un payload que no valida son
 * defectos que hay que ver: si el producto los sorteara aplazando, un paquete
 * saldria sin asiento y sin que nadie supiera por que. Sin fichero y sin
 * pendiente, y la salida no lleva el mensaje de la excepcion.
 */

uses(RefreshDatabase::class);

const DIAGNOSTICS_AUDIT_FAILURE_MARKER = 'kq_mensaje_de_excepcion_marcador';

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    FrozenTime::at('2026-06-15 09:00:00');

    $root = sys_get_temp_dir().'/kronoqr-diagnostics-audit-failure-'.bin2hex(random_bytes(6));
    diagnosticsAuditFailureRoot($root);

    Config::set('product.diagnostics_storage_path', $root.'/diagnostics');
    Config::set('product.diagnostics_pending_audit_path', $root.'/pending');
});

afterEach(function (): void {
    foreach (['diagnostics', 'pending'] as $directory) {
        foreach (glob(diagnosticsAuditFailureRoot().'/'.$directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(diagnosticsAuditFailureRoot().'/'.$directory);
    }

    @rmdir(diagnosticsAuditFailureRoot());
});

function diagnosticsAuditFailureRoot(?string $save = null): string
{
    static $root = '';

    if ($save !== null) {
        $root = $save;
    }

    return $root;
}

it('si el asiento falla con la base arriba, no hay paquete ni pendiente y la salida no lleva el mensaje (V4-PL-1)', function (): void {
    app()->bind(RecordDiagnosticsBundleGenerated::class, static function (): never {
        throw new RuntimeException(DIAGNOSTICS_AUDIT_FAILURE_MARKER);
    });

    $code = Artisan::call('product:diagnostics', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and(glob(diagnosticsAuditFailureRoot().'/diagnostics/*') ?: [])->toBe([])
        ->and(glob(diagnosticsAuditFailureRoot().'/pending/*') ?: [])->toBe([])
        ->and($output)->toContain('No se ha generado el paquete')
        ->and($output)->toContain('RuntimeException')
        ->and($output)->not->toContain(DIAGNOSTICS_AUDIT_FAILURE_MARKER);
})->group('RF-PD-09', 'RL-04', 'RL-19');
