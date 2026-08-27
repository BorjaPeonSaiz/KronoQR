<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * `compliance:purge-legal-export-temp` — hallazgo MEDIO-3 del cierre de la
 * Fase 1 (RF-IN-05).
 *
 * `LegalExportController` sirve la exportacion legal desde un temporal en
 * storage/framework/legal-exports/ y lo borra con `deleteFileAfterSend()` al
 * terminar. Si quien descarga aborta la conexion a medias, ese borrado nunca
 * corre. Estas pruebas comprueban lo que este comando promete: borra lo
 * viejo, respeta lo reciente, y NUNCA toca la copia deliberada de
 * `compliance:legal-export` en storage/app/legal-exports/.
 */

function legalExportTempDir(): string
{
    return storage_path('framework/legal-exports');
}

function legalExportConsoleDir(): string
{
    return storage_path('app/legal-exports');
}

/**
 * Crea un fichero con la antiguedad exacta que pide la prueba. `touch()` fija
 * el mtime; sin esto, todo fichero recien creado tendria "ahora" como fecha y
 * la ventana de retencion no se podria ejercitar.
 */
function crearFicheroConAntiguedad(string $path, int $hoursAgo): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, 'contenido de prueba, no un CSV real');
    touch($path, time() - ($hoursAgo * 3600));
}

afterEach(function (): void {
    // Limpieza del disco real: este comando no opera sobre un disco falso
    // (regla dura del propio comando: toca storage/framework de verdad), asi
    // que la prueba deja el arbol como lo encontro.
    foreach ([legalExportTempDir(), legalExportConsoleDir()] as $dir) {
        foreach (glob($dir.'/*.csv') ?: [] as $file) {
            @unlink($file);
        }
    }
});

it('borra los temporales huerfanos mas viejos que la ventana de retencion', function (): void {
    config(['compliance.legal_export_temp_retention_hours' => 1]);

    $viejo = legalExportTempDir().'/registro-horario-viejo.csv';
    crearFicheroConAntiguedad($viejo, hoursAgo: 2);

    $exitCode = Artisan::call('compliance:purge-legal-export-temp');

    expect($exitCode)->toBe(0);
    expect(is_file($viejo))->toBeFalse();
})->group('RF-IN-05');

it('no toca un temporal mas reciente que la ventana: podria ser una descarga en curso', function (): void {
    config(['compliance.legal_export_temp_retention_hours' => 6]);

    $reciente = legalExportTempDir().'/registro-horario-reciente.csv';
    crearFicheroConAntiguedad($reciente, hoursAgo: 1);

    Artisan::call('compliance:purge-legal-export-temp');

    expect(is_file($reciente))->toBeTrue();
})->group('RF-IN-05');

it('nunca toca la copia deliberada de consola en storage/app/legal-exports', function (): void {
    // Es la copia que se entrega a Inspeccion. Su custodia y su borrado son
    // responsabilidad de quien la genero (docs/runbooks/requerimiento-inspeccion.md
    // §6), no de un cron: si esta prueba fallara, un simulacro programado se
    // estaria comiendo la unica prueba entregada a un tercero.
    config(['compliance.legal_export_temp_retention_hours' => 1]);

    $copiaDeInspeccion = legalExportConsoleDir().'/registro-horario-2026-01-01_2026-01-31.csv';
    crearFicheroConAntiguedad($copiaDeInspeccion, hoursAgo: 24 * 30);

    Artisan::call('compliance:purge-legal-export-temp');

    expect(is_file($copiaDeInspeccion))->toBeTrue();
})->group('RF-IN-05');

it('no falla si el directorio de temporales todavia no existe', function (): void {
    // Una instalacion recien desplegada que nunca sirvio una exportacion por
    // HTTP no tiene ese directorio con contenido. El comando programado corre
    // cada hora desde el primer dia: no puede fallar por eso.
    //
    // El repositorio SI versiona storage/framework/legal-exports/.gitignore
    // (el patron habitual de Laravel para trackear un directorio vacio), asi
    // que "no existe" se simula quitando tambien ese fichero y restaurandolo
    // al terminar: la prueba no puede dejar el arbol de trabajo mas pobre de
    // lo que lo encontro.
    $dir = legalExportTempDir();
    $gitignore = $dir.'/.gitignore';
    $hadGitignore = is_file($gitignore);

    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            unlink($dir.'/'.$entry);
        }

        rmdir($dir);
    }

    try {
        $exitCode = Artisan::call('compliance:purge-legal-export-temp');

        expect($exitCode)->toBe(0);
    } finally {
        if ($hadGitignore) {
            mkdir($dir, 0755, true);
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }
})->group('RF-IN-05');
