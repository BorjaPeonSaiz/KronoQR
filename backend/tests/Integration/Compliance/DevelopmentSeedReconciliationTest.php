<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;

/*
 * La semilla de desarrollo no dispara la alerta de conciliacion registro ↔
 * auditoria (ADR-057 §4, RL-04, RS-07; bloque 11 de la 2.2.1).
 *
 * POR QUE. Hasta la 2.2.1 las semillas escribian `shift_entries` y
 * `shift_corrections` a mano y ningun asiento de `audit_log`: nada mas sembrar,
 * `compliance:reconcile-work-record` daba unas veinte mil discrepancias
 * `entry_without_audit` y la alerta de manipulacion saltaba en todo entorno de
 * desarrollo. Una alerta que salta siempre enseña a ignorarla. Ahora los tramos
 * del quiosco llevan los asientos que escribe el listener del producto y las
 * correcciones pasan por sus casos de uso; esta prueba lo fija.
 *
 * POR QUE INTEGRACION. Es la relacion entre tres tablas de PostgreSQL, sus
 * *triggers* de ADR-057 y la cadena de hash: un doble en memoria no ve ninguna.
 *
 * POR QUE CommittedDatabase. Como en `MigrationsRoundTripTest`: la semilla abre
 * sus propias transacciones y las confirma, y las comprobaciones diferidas de
 * los *triggers* de `shift_entries` solo corren al confirmar. Dentro de la
 * transaccion envolvente de `RefreshDatabase` no correrian nunca.
 *
 * LA SEMILLA ENTERA, con los noventa dias del `VolumeSeeder`, y no un trozo: lo
 * que se afirma es que `make seed` deja un entorno sin alerta, y cualquier
 * semilla que se añada mañana entra en la prueba sin tocarla.
 */

uses(CommittedDatabase::class);

it('la semilla de desarrollo deja el registro horario cuadrado con su auditoria', function (): void {
    $metricas = sys_get_temp_dir().'/kronoqr-semilla-conciliacion-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $metricas);
    Config::set('observability.metrics.enabled', true);

    try {
        [$sembrada, $salidaSemilla] = Commands::run('db:seed --force');

        expect($sembrada)->toBe(0, $salidaSemilla);

        // La semilla tiene lo que viene a tener: volumen, casos limite y las tres
        // correcciones, con una version sustituida y un tramo anulado. Sin esto,
        // una semilla vacia cuadraria igual de bien.
        expect(DB::table('shift_entries')->count())->toBeGreaterThan(1000)
            ->and(DB::table('shift_entries')->where('status', 'open')->count())->toBe(1)
            ->and(DB::table('shift_entries')->where('status', 'superseded')->count())->toBe(1)
            ->and(DB::table('shift_entries')->where('status', 'voided')->count())->toBe(1)
            ->and(DB::table('shift_corrections')->count())->toBe(3)
            ->and(DB::table('shift_entries')->where('clock_in_source', 'manual_admin')->count())->toBe(1);

        [$completa, $salidaCompleta] = Commands::run('compliance:reconcile-work-record --full');
        [$diaria, $salidaDiaria] = Commands::run('compliance:reconcile-work-record');
        [$cadena, $salidaCadena] = Commands::run('compliance:verify-audit-chain');

        expect($completa)->toBe(0, $salidaCompleta)
            ->and($salidaCompleta)->toContain('Registro y auditoria cuadran (registro completo)')
            ->and($diaria)->toBe(0, $salidaDiaria)
            // Los asientos van por el camino de escritura de la auditoria, no
            // por un INSERT: la cadena sigue integra.
            ->and($cadena)->toBe(0, $salidaCadena);

        // Y la alerta, que se dispara con la metrica, tampoco: las cinco clases
        // de discrepancia a cero en la pasada completa.
        $fichero = $metricas.'/kronoqr_work_record_reconciliation_full.prom';

        expect(is_file($fichero))->toBeTrue('La conciliacion no ha publicado '.$fichero.'.');

        $publicada = (string) file_get_contents($fichero);

        foreach (WorkRecordDiscrepancyKind::cases() as $clase) {
            expect($publicada)->toContain('work_record_reconciliation_discrepancies{scope="full",kind="'.$clase->value.'"} 0');
        }
    } finally {
        File::deleteDirectory($metricas);
    }
})->group('RL-04', 'RS-07', 'RN-13');
