<?php

declare(strict_types=1);

use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\RecordingGeneratedFileMetrics;
use Tests\Support\Time\FrozenTime;

/*
 * `compliance:purge-legal-export-temp` — hallazgo MEDIO-3 del cierre de la
 * Fase 1 (RF-IN-05) y ADR-045.
 *
 * `LegalExportController` sirve la exportacion legal desde un temporal en
 * storage/app/tmp/legal-exports/ y lo borra con `deleteFileAfterSend()` al
 * terminar. Si quien descarga aborta la conexion a medias, ese borrado nunca
 * corre. Estas pruebas comprueban lo que este comando promete: borra lo viejo,
 * respeta lo reciente, y NUNCA toca la copia deliberada de
 * `compliance:legal-export` en storage/app/legal-exports/ — aunque si la cuenta
 * cuando lleva mas de 30 dias.
 *
 * ## Como se envejece un fichero
 *
 * La edad es `max(mtime, ctime)` (C9) y `ctime` es el instante en que se creo el
 * fichero: no se puede fabricar un fichero viejo con `touch`. Se adelanta el
 * reloj inyectado. Las dos raices apuntan a directorios temporales propios: la
 * prueba no toca el `storage/` del repositorio.
 */

beforeEach(function (): void {
    config([
        'compliance.legal_export_temp_path' => GeneratedFilesSandbox::directory('legal-tmp'),
        'compliance.legal_export_console_path' => GeneratedFilesSandbox::directory('legal-console'),
    ]);
});

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

function temporalLegal(string $nombre): string
{
    return GeneratedFilesSandbox::file(config()->string('compliance.legal_export_temp_path').'/'.$nombre);
}

function copiaLegalDeConsola(string $nombre): string
{
    return GeneratedFilesSandbox::file(config()->string('compliance.legal_export_console_path').'/'.$nombre);
}

function relojLegalDentroDe(int $seconds): void
{
    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + $seconds));
}

it('borra los temporales huerfanos mas viejos que la ventana de retencion', function (): void {
    config(['compliance.legal_export_temp_retention_hours' => 1]);
    $viejo = temporalLegal('registro-horario-2026-01-01_2026-01-31-AbCdEf123456.csv');

    relojLegalDentroDe(2 * 3600);
    [$codigo] = Commands::run('compliance:purge-legal-export-temp');

    expect($codigo)->toBe(0)
        ->and(is_file($viejo))->toBeFalse();
})->group('RF-IN-05');

it('no toca un temporal mas reciente que la ventana: podria ser una descarga en curso', function (): void {
    config(['compliance.legal_export_temp_retention_hours' => 6]);
    $reciente = temporalLegal('registro-horario-reciente.csv');

    relojLegalDentroDe(3600);
    Commands::run('compliance:purge-legal-export-temp');

    expect(is_file($reciente))->toBeTrue();
})->group('RF-IN-05');

it('no adelanta el borrado de un temporal recien escrito con un mtime viejo', function (): void {
    // C9: `touch -d` hacia atras no envejece un fichero; manda ctime.
    config(['compliance.legal_export_temp_retention_hours' => 1]);
    $reciente = temporalLegal('registro-horario-retocado.csv');
    touch($reciente, time() - 48 * 3600);

    relojLegalDentroDe(60);
    Commands::run('compliance:purge-legal-export-temp');

    expect(is_file($reciente))->toBeTrue();
})->group('RF-IN-05');

it('solo borra lo que casa con el nombre del temporal', function (): void {
    config(['compliance.legal_export_temp_retention_hours' => 1]);
    $ajeno = temporalLegal('notas.txt');

    relojLegalDentroDe(48 * 3600);
    Commands::run('compliance:purge-legal-export-temp');

    expect(is_file($ajeno))->toBeTrue();
})->group('RF-IN-05');

it('nunca toca la copia deliberada de consola, pero la cuenta y avisa a los 30 dias', function (): void {
    // Es la copia que se entrega a Inspeccion. Su custodia y su borrado son
    // responsabilidad de quien la genero (docs/runbooks/requerimiento-inspeccion.md
    // §6), no de un cron: si esta prueba fallara, un borrado programado se
    // estaria comiendo la unica prueba entregada a un tercero.
    config(['compliance.legal_export_temp_retention_hours' => 1]);
    $metricas = RecordingGeneratedFileMetrics::install();
    $copiaDeInspeccion = copiaLegalDeConsola('registro-horario-2026-01-01_2026-01-31.csv');

    relojLegalDentroDe(31 * 86400);
    [$codigo, $salida] = Commands::run('compliance:purge-legal-export-temp');

    expect($codigo)->toBe(0)
        ->and(is_file($copiaDeInspeccion))->toBeTrue()
        ->and($metricas->overdue)->toBe(['legal_export_console' => 1])
        ->and($salida)->toContain('borralas en cuanto las hayas entregado')
        // Ni la ruta ni el nombre en la salida que va al log del planificador.
        ->and($salida)->not->toContain('registro-horario-2026-01-01');
})->group('RF-IN-05', 'RL-19');

it('publica cero exportaciones de consola vencidas cuando no las hay', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    copiaLegalDeConsola('registro-horario-2026-01-01_2026-01-31.csv');

    relojLegalDentroDe(29 * 86400);
    [, $salida] = Commands::run('compliance:purge-legal-export-temp');

    // Cero tambien se publica: es lo que apaga la alerta cuando alguien las borra.
    expect($metricas->overdue)->toBe(['legal_export_console' => 0])
        ->and($salida)->not->toContain('borralas');
})->group('RF-IN-05');

it('no falla si los directorios todavia no existen', function (): void {
    // Una instalacion recien desplegada que nunca sirvio una exportacion por
    // HTTP no tiene ese directorio. El comando programado corre cada hora desde
    // el primer dia: no puede fallar por eso.
    config([
        'compliance.legal_export_temp_path' => sys_get_temp_dir().'/kronoqr-no-existe-'.bin2hex(random_bytes(4)),
        'compliance.legal_export_console_path' => sys_get_temp_dir().'/kronoqr-no-existe-'.bin2hex(random_bytes(4)),
    ]);

    [$codigo] = Commands::run('compliance:purge-legal-export-temp');

    expect($codigo)->toBe(0);
})->group('RF-IN-05');
