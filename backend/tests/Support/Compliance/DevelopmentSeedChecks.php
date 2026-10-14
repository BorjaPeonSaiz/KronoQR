<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;

/**
 * Lo que se afirma de la semilla de desarrollo recien sembrada (ADR-057 §4,
 * RL-04, RS-07, RN-13; bloque 11 de la 2.2.1).
 *
 * POR QUE EXISTE. Hasta la 2.2.1 las semillas escribian `shift_entries` y
 * `shift_corrections` a mano y ningun asiento de `audit_log`: nada mas sembrar,
 * `compliance:reconcile-work-record` daba unas veinte mil discrepancias
 * `entry_without_audit` y la alerta de manipulacion saltaba en todo entorno de
 * desarrollo. Una alerta que salta siempre enseña a ignorarla.
 *
 * POR QUE UN AYUDANTE. La semilla completa —los noventa dias del
 * `VolumeSeeder`— cuesta en torno a noventa segundos, y la prueba que ya la
 * siembra es `MigrationsRoundTripTest`. Estas comprobaciones corren alli, tras
 * sembrar y antes de deshacer las migraciones, en vez de sembrar otra vez en
 * una prueba propia (revision del bloque 11).
 */
final class DevelopmentSeedChecks
{
    /**
     * La semilla tiene lo que viene a tener: volumen, casos limite y las tres
     * correcciones, con una version sustituida y un tramo anulado. Sin esto,
     * una semilla vacia cuadraria igual de bien.
     */
    public static function assertExpectedContent(): void
    {
        expect(DB::table('shift_entries')->count())->toBeGreaterThan(1000)
            ->and(DB::table('shift_entries')->where('status', 'open')->count())->toBe(1)
            ->and(DB::table('shift_entries')->where('status', 'superseded')->count())->toBe(1)
            ->and(DB::table('shift_entries')->where('status', 'voided')->count())->toBe(1)
            ->and(DB::table('shift_corrections')->count())->toBe(3)
            ->and(DB::table('shift_entries')->where('clock_in_source', 'manual_admin')->count())->toBe(1);
    }

    /**
     * El registro cuadra con su auditoria en la pasada completa y en la diaria,
     * la cadena de hash sigue integra y la metrica que dispara la alerta
     * publica las cinco clases de discrepancia a cero.
     *
     * Exige que la configuracion de metricas apunte a `$metricsDirectory` y
     * este encendida antes de llamarla.
     */
    public static function assertReconciled(string $metricsDirectory): void
    {
        [$full, $fullOutput] = Commands::run('compliance:reconcile-work-record --full');
        [$daily, $dailyOutput] = Commands::run('compliance:reconcile-work-record');
        [$chain, $chainOutput] = Commands::run('compliance:verify-audit-chain');

        expect($full)->toBe(0, $fullOutput)
            ->and($fullOutput)->toContain('Registro y auditoria cuadran (registro completo)')
            ->and($daily)->toBe(0, $dailyOutput)
            // Los asientos van por el camino de escritura de la auditoria, no
            // por un INSERT: la cadena sigue integra.
            ->and($chain)->toBe(0, $chainOutput);

        $file = $metricsDirectory.'/kronoqr_work_record_reconciliation_full.prom';

        expect(is_file($file))->toBeTrue('La conciliacion no ha publicado '.$file.'.');

        $published = (string) file_get_contents($file);

        foreach (WorkRecordDiscrepancyKind::cases() as $kind) {
            expect($published)->toContain('work_record_reconciliation_discrepancies{scope="full",kind="'.$kind->value.'"} 0');
        }
    }
}
