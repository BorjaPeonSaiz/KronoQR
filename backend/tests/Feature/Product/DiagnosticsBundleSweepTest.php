<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\SweepExpiredDiagnosticsBundles;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\RecordingGeneratedFileMetrics;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El barrido horario de los paquetes de diagnostico (ADR-045 §g, condicion C4;
 * RF-PD-09, RL-19).
 *
 * Con el volumen `app-storage` persistente, un paquete pedido con
 * `--with-personal-data` ya no desaparece al recrear el contenedor: si nadie
 * generara otro, se quedaria en el servidor para siempre. La pasada horaria de
 * `product:export-all --purge` lo barre al superar
 * `PRODUCT_DIAGNOSTICS_RETENTION_DAYS`, y solo a el: patron exacto, un nivel,
 * sin seguir enlaces.
 *
 * ## Como se envejece un fichero aqui
 *
 * Igual que en `DataExportReconciliationTest`: la edad es `max(mtime, ctime)` y
 * `ctime` no se puede fijar, asi que se adelanta el reloj inyectado respecto
 * del reloj real en vez de envejecer el fichero.
 */

const DIAGNOSTICS_SWEEP_DAY = 86400;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    diagnosticsSweepClockIn(0);
    WorkforceFixtures::site();
    LicenseKeys::install();

    config(['product.diagnostics_storage_path' => GeneratedFilesSandbox::directory('diagnosticos')]);
    config(['product.diagnostics_retention_days' => 7]);

    // La pasada horaria concilia tambien las exportaciones: que no toque las
    // del entorno de desarrollo.
    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
    GeneratedFilesSandbox::cleanUp();
});

function diagnosticsSweepClockIn(int $seconds): void
{
    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + $seconds));
}

function diagnosticsSweepRoot(): string
{
    return config()->string('product.diagnostics_storage_path');
}

/** Un paquete con el nombre exacto que produce `DiagnosticsManifest::fileName()`. */
function diagnosticsSweepBundle(string $stamp = '20261002T120000Z', string $version = '2.2.0'): string
{
    return GeneratedFilesSandbox::file(diagnosticsSweepRoot().'/kronoqr-diagnostics-'.$version.'-'.$stamp.'.json', '{}');
}

/** @return array{code: int, output: string} */
function diagnosticsSweepHourlyPass(): array
{
    $code = Artisan::call('product:export-all', ['--purge' => true]);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('la pasada horaria borra el paquete que supera su plazo y lo cuenta sin nombrarlo', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $viejo = diagnosticsSweepBundle();

    diagnosticsSweepClockIn(8 * DIAGNOSTICS_SWEEP_DAY);
    $resultado = diagnosticsSweepHourlyPass();

    expect($resultado['code'])->toBe(0)
        ->and(is_file($viejo))->toBeFalse('El paquete de mas de 7 dias sigue en el disco.')
        ->and($metricas->orphans)->toBe(['diagnostics'])
        ->and($resultado['output'])->toContain('Borrados 1 paquetes de diagnostico')
        // Solo la cifra: ni nombre de fichero ni ruta (regla dura 21).
        ->and($resultado['output'])->not->toContain('kronoqr-diagnostics-')
        ->and($resultado['output'])->not->toContain(diagnosticsSweepRoot());
})->group('RF-PD-09', 'RL-19');

it('la pasada horaria no toca un paquete que aun no ha cumplido su plazo', function (): void {
    $reciente = diagnosticsSweepBundle();

    diagnosticsSweepClockIn(6 * DIAGNOSTICS_SWEEP_DAY);
    $resultado = diagnosticsSweepHourlyPass();

    expect(is_file($reciente))->toBeTrue('Se ha borrado un paquete que aun no habia caducado.')
        ->and($resultado['output'])->not->toContain('paquetes de diagnostico');
})->group('RF-PD-09', 'RL-19');

it('el plazo sale de la configuracion y no de una constante', function (): void {
    // Regla dura 13: un cliente con una politica mas dura lo baja sin tocar el codigo.
    config(['product.diagnostics_retention_days' => 1]);
    $deAyer = diagnosticsSweepBundle();

    diagnosticsSweepClockIn(2 * DIAGNOSTICS_SWEEP_DAY);

    expect(app(SweepExpiredDiagnosticsBundles::class)->handle())->toBe(1)
        ->and(is_file($deAyer))->toBeFalse();
})->group('RF-PD-09', 'RL-19');

it('no toca en la carpeta nada que no tenga el nombre exacto de un paquete', function (): void {
    $notas = GeneratedFilesSandbox::file(diagnosticsSweepRoot().'/notas-del-administrador.json', '{}');
    $copia = GeneratedFilesSandbox::file(diagnosticsSweepRoot().'/kronoqr-diagnostics-2.2.0-20261002T120000Z.json.bak', '{}');
    $otroPrefijo = GeneratedFilesSandbox::file(diagnosticsSweepRoot().'/diagnostics-2.2.0.json', '{}');
    $anidado = GeneratedFilesSandbox::file(diagnosticsSweepRoot().'/enviados/kronoqr-diagnostics-2.2.0-20261002T120000Z.json', '{}');

    diagnosticsSweepClockIn(365 * DIAGNOSTICS_SWEEP_DAY);
    diagnosticsSweepHourlyPass();

    expect(is_file($notas))->toBeTrue()
        ->and(is_file($copia))->toBeTrue()
        ->and(is_file($otroPrefijo))->toBeTrue()
        // Un solo nivel: lo que hay dentro de un subdirectorio no existe para el barrido.
        ->and(is_file($anidado))->toBeTrue();
})->group('RF-PD-09', 'RL-19');

it('no sigue un enlace simbolico con nombre de paquete y lo cuenta como rechazo', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $ajeno = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('ajeno').'/copia.dump');
    $enlace = diagnosticsSweepRoot().'/kronoqr-diagnostics-2.2.0-20261002T120000Z.json';
    symlink($ajeno, $enlace);

    diagnosticsSweepClockIn(30 * DIAGNOSTICS_SWEEP_DAY);
    diagnosticsSweepHourlyPass();

    expect(is_file($ajeno))->toBeTrue('El barrido ha seguido el enlace y ha borrado un fichero de fuera.')
        ->and(is_link($enlace))->toBeTrue()
        ->and($metricas->refused)->toBe(['diagnostics']);
})->group('RF-PD-09', 'RL-19');

it('barre igual el paquete con datos personales: el nombre no distingue', function (): void {
    // El escritor da el mismo nombre al anonimizado y al que lleva datos
    // personales (`DiagnosticsManifest::fileName()`), y es el segundo el que
    // de verdad no puede quedarse en el disco.
    Artisan::call('product:diagnostics', ['--with-personal-data' => true, '--no-interaction' => true]);

    $escritos = glob(diagnosticsSweepRoot().'/kronoqr-diagnostics-*.json') ?: [];

    expect($escritos)->toHaveCount(1);

    diagnosticsSweepClockIn(8 * DIAGNOSTICS_SWEEP_DAY);
    diagnosticsSweepHourlyPass();

    expect(glob(diagnosticsSweepRoot().'/kronoqr-diagnostics-*.json') ?: [])->toBe([]);
})->group('RF-PD-09', 'RL-19');
