<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\GeneratedFilesProbe;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Infrastructure\GeneratedFiles\GeneratedFileAreas;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Time\FrozenTime;

/*
 * Las sondas `files.*` de `product:doctor` (RF-PD-13; ADR-045, condicion C3).
 *
 * El fallo que cierra ADR-045 no daba ningun sintoma hasta que alguien pedia una
 * exportacion desde el panel en produccion. Estas sondas son las que lo dicen
 * en la instalacion: raices de clase que se pisan, `storage/app` sin volumen
 * propio, informes de retencion donde nadie los ve y exportaciones legales de
 * consola olvidadas.
 *
 * La sonda se construye a mano con sus directorios en un arbol temporal: lo que
 * se prueba es su decision, no la configuracion de este contenedor.
 */

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

/**
 * Una sonda sobre un `storage/app` y un `BACKUP_PATH` temporales.
 *
 * @param  array<string, string>|null  $raices
 */
function sondaDeFicheros(
    string $storageApp,
    ?array $raices = null,
    ?string $informes = null,
    ?string $copias = null,
    string $entorno = 'testing',
    ?string $aplicacion = null,
): GeneratedFilesProbe {
    $copias ??= GeneratedFilesSandbox::directory('backups');

    return new GeneratedFilesProbe(
        storageAppPath: $storageApp,
        applicationPath: $aplicacion ?? \dirname($storageApp),
        environment: $entorno,
        retentionReportPath: $informes ?? $copias,
        backupPath: $copias,
        classRoots: $raices ?? [
            'PRODUCT_DATA_EXPORT_PATH' => $storageApp.'/exports',
            'REPORTING_EXPORT_PATH' => $storageApp.'/reports',
            'storage/app/tmp/legal-exports' => $storageApp.'/tmp/legal-exports',
            'storage/app/legal-exports' => $storageApp.'/legal-exports',
            'PRODUCT_DIAGNOSTICS_PATH' => $storageApp.'/diagnostics',
            'TELEMETRY_STATE_PATH' => $storageApp.'/telemetry',
        ],
        consoleExports: GeneratedFileAreas::legalExportConsole($storageApp.'/legal-exports'),
        consoleWarningDays: 30,
        files: app(GeneratedFileHousekeeping::class),
        clock: app(Clock::class),
        store: app(GeneratedFileStore::class),
    );
}

function hallazgoDeFicheros(GeneratedFilesProbe $sonda, string $id): DoctorFinding
{
    foreach ($sonda->run() as $finding) {
        if ($finding->id === $id) {
            return $finding;
        }
    }

    throw new RuntimeException('La sonda no devuelve «'.$id.'».');
}

it('da por buenas las raices de serie, cada una en su directorio de storage/app', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    expect(hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.class_roots')->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13');

it('falla si dos raices de clase coinciden o una contiene a otra', function (string $segunda): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, [
        'PRODUCT_DATA_EXPORT_PATH' => $storageApp.'/exports',
        'REPORTING_EXPORT_PATH' => $storageApp.$segunda,
    ]), 'files.class_roots');

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBe('overlap')
        ->and($hallazgo->params)->toBe(['first' => 'PRODUCT_DATA_EXPORT_PATH', 'second' => 'REPORTING_EXPORT_PATH']);
})->with([
    'la misma' => ['/exports'],
    'la misma con barra final' => ['/exports/'],
    'una dentro de otra' => ['/exports/reports'],
])->group('RF-PD-13');

it('falla si una raiz de clase es storage/app o lo contiene', function (string $raiz): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, [
        'REPORTING_EXPORT_PATH' => str_replace('{storage}', $storageApp, $raiz),
    ]), 'files.class_roots');

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBe('storage_root');
})->with([
    'storage/app' => ['{storage}'],
    'su padre' => ['{storage}/..'],
])->group('RF-PD-13');

it('falla si una raiz de clase se pisa con BACKUP_PATH', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');
    $copias = GeneratedFilesSandbox::directory('backups');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, [
        'PRODUCT_DATA_EXPORT_PATH' => $copias.'/exports',
    ], copias: $copias), 'files.class_roots');

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBe('backup_path');
})->group('RF-PD-13');

it('en produccion falla con una raiz fuera de storage/app: es R3-PL-01 otra vez', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, [
        'PRODUCT_DATA_EXPORT_PATH' => GeneratedFilesSandbox::directory('srv-exports'),
    ], entorno: 'production'), 'files.class_roots');

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBe('outside_volume')
        ->and($hallazgo->params)->toBe(['names' => 'PRODUCT_DATA_EXPORT_PATH']);
})->group('RF-PD-13');

it('avisa de ficheros generados en storage/app fuera de las raices configuradas', function (): void {
    // Lo que deja cambiar PRODUCT_DATA_EXPORT_PATH: la raiz antigua con ZIP que
    // ninguna purga mira ya.
    $storageApp = GeneratedFilesSandbox::directory('storage-app');
    GeneratedFilesSandbox::file($storageApp.'/exports-antiguo/kronoqr-export-2.1.0-20260101T000000Z.zip');
    GeneratedFilesSandbox::file($storageApp.'/notas/leeme.txt');
    GeneratedFilesSandbox::file($storageApp.'/exports/kronoqr-export-2.2.0-20261002T000000Z.zip');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.stray_entries');
    $limpio = hallazgoDeFicheros(sondaDeFicheros(GeneratedFilesSandbox::directory('storage-limpio')), 'files.stray_entries');

    expect($hallazgo->status)->toBe(DoctorStatus::Warning)
        // Solo la carpeta con ficheros de una clase; ni la configurada ni la ajena.
        ->and($hallazgo->params)->toBe(['names' => 'exports-antiguo'])
        ->and($limpio->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RL-11');

it('avisa de una raiz fuera de storage/app, que ningun otro contenedor ve', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, [
        'PRODUCT_DIAGNOSTICS_PATH' => GeneratedFilesSandbox::directory('fuera'),
    ]), 'files.class_roots');

    expect($hallazgo->status)->toBe(DoctorStatus::Warning)
        ->and($hallazgo->variant)->toBe('outside_volume')
        ->and($hallazgo->params)->toBe(['names' => 'PRODUCT_DIAGNOSTICS_PATH']);
})->group('RF-PD-13');

it('en produccion falla si storage/app no es un volumen propio', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, entorno: 'production'), 'files.storage_volume');

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBe('not_mounted');
})->group('RF-PD-13');

it('fuera de produccion no exige el volumen propio, pero lo dice', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.storage_volume');

    expect($hallazgo->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgo->variant)->toBe('not_checked');
})->group('RF-PD-13');

it('da por montado un storage/app en otro dispositivo que la aplicacion', function (): void {
    // `/proc` es otro sistema de ficheros en cualquier contenedor Linux: basta
    // para comprobar la comparacion de dispositivos sin montar nada.
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $hallazgo = hallazgoDeFicheros(sondaDeFicheros($storageApp, entorno: 'production', aplicacion: '/proc'), 'files.storage_volume');

    expect($hallazgo->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgo->variant)->toBeNull();
})->group('RF-PD-13');

it('falla si storage/app no existe o no se puede escribir', function (): void {
    $hallazgo = hallazgoDeFicheros(
        sondaDeFicheros(sys_get_temp_dir().'/kronoqr-no-existe-'.bin2hex(random_bytes(4))),
        'files.storage_volume',
    );

    expect($hallazgo->status)->toBe(DoctorStatus::Failure)
        ->and($hallazgo->variant)->toBeNull();
})->group('RF-PD-13');

it('avisa si los informes de retencion van dentro de storage/app o a un directorio que no existe', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    $dentro = hallazgoDeFicheros(sondaDeFicheros($storageApp, informes: $storageApp.'/retention-reports'), 'files.retention_reports');
    $falta = hallazgoDeFicheros(sondaDeFicheros($storageApp, informes: '/no/existe/reports/retention'), 'files.retention_reports');
    $bien = hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.retention_reports');

    expect([$dentro->status, $dentro->variant])->toBe([DoctorStatus::Warning, 'inside_storage'])
        ->and([$falta->status, $falta->variant])->toBe([DoctorStatus::Warning, 'missing'])
        ->and($bien->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RF-PR-03');

it('avisa, y nunca falla, de exportaciones legales de consola con mas de 30 dias', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');
    $copia = GeneratedFilesSandbox::file($storageApp.'/legal-exports/registro-horario-2026-01-01_2026-01-31.csv');

    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + 29 * 86400));
    $antes = hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.legal_exports_console');

    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + 31 * 86400));
    $despues = hallazgoDeFicheros(sondaDeFicheros($storageApp), 'files.legal_exports_console');

    expect($antes->status)->toBe(DoctorStatus::Ok)
        ->and($despues->status)->toBe(DoctorStatus::Warning)
        ->and($despues->params['count'])->toBe(1)
        ->and($despues->params['days'])->toBe(30)
        // Custodia humana: la sonda avisa y no toca nada.
        ->and(is_file($copia))->toBeTrue();
})->group('RF-PD-13', 'RF-IN-05');

it('cada hallazgo de la familia files tiene texto y que hacer en los dos idiomas', function (): void {
    // Las variantes que no salen en este contenedor tambien: una clave sin texto
    // solo se descubre el dia que la instalacion del cliente cae en ese caso.
    $variantes = [
        ['storage_volume', 'ok', null], ['storage_volume', 'ok', 'not_checked'],
        ['storage_volume', 'failure', null], ['storage_volume', 'failure', 'not_mounted'],
        ['retention_reports', 'ok', null], ['retention_reports', 'warning', null],
        ['retention_reports', 'warning', 'missing'], ['retention_reports', 'warning', 'inside_storage'],
        ['class_roots', 'ok', null], ['class_roots', 'failure', 'overlap'],
        ['class_roots', 'failure', 'storage_root'], ['class_roots', 'failure', 'backup_path'],
        ['class_roots', 'warning', 'outside_volume'], ['class_roots', 'failure', 'outside_volume'],
        ['stray_entries', 'ok', null], ['stray_entries', 'warning', null],
        ['legal_exports_console', 'ok', null], ['legal_exports_console', 'warning', null],
    ];

    foreach (['es', 'en'] as $idioma) {
        foreach ($variantes as [$id, $estado, $variante]) {
            $hallazgo = new DoctorFinding('files.'.$id, DoctorStatus::from($estado), variant: $variante);

            expect(trans($hallazgo->messageKey(), [], $idioma))->not->toBe($hallazgo->messageKey(), $idioma.' '.$hallazgo->messageKey());

            if ($hallazgo->fixKey() !== null) {
                expect(trans($hallazgo->fixKey(), [], $idioma))->not->toBe($hallazgo->fixKey(), $idioma.' '.$hallazgo->fixKey());
            }
        }
    }
})->group('RF-PD-13');

it('product:doctor incluye las cinco comprobaciones de ficheros', function (): void {
    $ids = array_map(
        static fn (DoctorCheck $check): string => $check->id,
        app(RunDoctorHandler::class)->handle('es')->checks,
    );

    expect($ids)->toContain('files.storage_volume', 'files.retention_reports', 'files.class_roots', 'files.stray_entries', 'files.legal_exports_console');
})->group('RF-PD-13');

/**
 * Los marcadores `:algo` que el traductor no sustituyo en un texto ya renderizado.
 *
 * @return list<string>
 */
function marcadoresSinSustituir(string $texto): array
{
    preg_match_all('/(?<![A-Za-z0-9\/]):[a-z][a-z_]*/', $texto, $marcadores);

    return $marcadores[0];
}

it('ningun texto de product:doctor deja un marcador :algo sin sustituir', function (): void {
    // El fallo que encontro la instalacion real: `ok()` recibe primero los
    // detalles y despues los parametros, y `files.retention_reports` imprimia
    // «se escriben en :path». La prueba de «cada hallazgo lleva su texto» solo
    // miraba que la clave existiera, no que el texto saliera completo.
    foreach (['es', 'en'] as $idioma) {
        foreach (app(RunDoctorHandler::class)->handle($idioma)->checks as $check) {
            expect(marcadoresSinSustituir($check->summary.' '.($check->fix ?? '')))
                ->toBe([], $idioma.' '.$check->id.': '.$check->summary);
        }
    }
})->group('RF-PD-13');

it('ningun resultado posible de las sondas files.* deja un marcador sin sustituir', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('storage-app');
    GeneratedFilesSandbox::file($storageApp.'/viejo/kronoqr-export-2.1.0-X.zip');
    GeneratedFilesSandbox::file($storageApp.'/legal-exports/registro-horario-2026-01-01_2026-01-31.csv');
    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + 31 * 86400));
    $fuera = GeneratedFilesSandbox::directory('fuera');

    $sondas = [
        sondaDeFicheros($storageApp),
        sondaDeFicheros($storageApp, entorno: 'production'),
        sondaDeFicheros($storageApp, entorno: 'production', aplicacion: '/proc'),
        sondaDeFicheros(sys_get_temp_dir().'/kronoqr-no-existe-'.bin2hex(random_bytes(4))),
        sondaDeFicheros($storageApp, informes: $storageApp.'/retention-reports'),
        sondaDeFicheros($storageApp, informes: '/no/existe/reports/retention'),
        sondaDeFicheros($storageApp, ['A' => $storageApp.'/x', 'B' => $storageApp.'/x/y']),
        sondaDeFicheros($storageApp, ['A' => $storageApp]),
        sondaDeFicheros($storageApp, ['A' => $fuera], entorno: 'production'),
        sondaDeFicheros($storageApp, ['A' => $fuera]),
    ];

    $traductor = app(DoctorTranslator::class);

    foreach ($sondas as $sonda) {
        foreach ($sonda->run() as $hallazgo) {
            foreach (['es', 'en'] as $idioma) {
                $texto = (string) $traductor->translate($hallazgo->messageKey(), $hallazgo->params, $idioma)
                    .' '.($hallazgo->fixKey() === null ? '' : (string) $traductor->translate($hallazgo->fixKey(), $hallazgo->params, $idioma));

                expect(marcadoresSinSustituir($texto))->toBe([], $idioma.' '.$hallazgo->messageKey().': '.$texto);
            }
        }
    }
})->group('RF-PD-13');
