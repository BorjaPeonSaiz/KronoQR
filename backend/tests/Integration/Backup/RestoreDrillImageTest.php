<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La imagen que usa `restore-drill.sh --mode pitr` (ADR-053, RNF-D-05).
 *
 * El simulacro tiene que probar la MISMA imagen de postgres que corre la
 * instalacion: la que el paquete fija por digest (`images.lock`, o
 * IMAGE_DIGEST_POSTGRES si el .env la define), no la que resuelva una etiqueta.
 * Se carga el script con `source`, sin lanzar ningun simulacro, desde una copia
 * en un directorio temporal para poder poner un `images.lock` junto a el.
 */

const RESTORE_DRILL_DIGEST_A = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const RESTORE_DRILL_DIGEST_B = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * @param  array<string, string>  $env
 */
function imagenDelSimulacro(?string $lock, array $env = [], int $exitCode = 0): string
{
    $dir = sys_get_temp_dir().'/kq-drill-image-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    copy(Repo::file('infra/scripts/restore-drill.sh'), $dir.'/restore-drill.sh');
    exec('cp -r '.escapeshellarg(Repo::file('infra/scripts/lib')).' '.escapeshellarg($dir.'/lib'));
    if ($lock !== null) {
        file_put_contents($dir.'/images.lock', $lock);
    }

    $process = new Process(
        ['bash', '-c', 'set -euo pipefail; . '.escapeshellarg($dir.'/restore-drill.sh').'; imagen_del_producto postgres "${IMAGEN}"'],
        env: array_merge(['BACKUP_ENV_FILE' => '/dev/null', 'KRONOQR_LANG' => 'es'], $env),
        timeout: 30.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe($exitCode, $process->getErrorOutput());

    return $exitCode === 0 ? $process->getOutput() : $process->getErrorOutput();
}

it('usa el digest de images.lock del paquete y no la etiqueta', function (): void {
    $lock = "php sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n"
        .'postgres '.RESTORE_DRILL_DIGEST_A."\n";

    expect(imagenDelSimulacro($lock, ['IMAGE_TAG' => '2.2.1']))
        ->toBe('ghcr.io/kronoqr/postgres@'.RESTORE_DRILL_DIGEST_A);
})->group('RNF-D-05', 'ADR-053');

it('IMAGE_DIGEST_POSTGRES del .env manda sobre images.lock, con o sin arroba', function (string $valor): void {
    expect(imagenDelSimulacro('postgres '.RESTORE_DRILL_DIGEST_A."\n", ['IMAGE_DIGEST_POSTGRES' => $valor, 'IMAGE_TAG' => '2.2.1']))
        ->toBe('ghcr.io/kronoqr/postgres@'.RESTORE_DRILL_DIGEST_B);
})->with([
    'con arroba' => ['@'.RESTORE_DRILL_DIGEST_B],
    'sin arroba' => [RESTORE_DRILL_DIGEST_B],
])->group('RNF-D-05', 'ADR-053');

it('IMAGE_DIGEST_POSTGRES definida y vacia es la excepcion documentada: se usa la etiqueta', function (): void {
    expect(imagenDelSimulacro('postgres '.RESTORE_DRILL_DIGEST_A."\n", ['IMAGE_DIGEST_POSTGRES' => '', 'IMAGE_TAG' => '2.2.1']))
        ->toBe('ghcr.io/kronoqr/postgres:2.2.1');
})->group('RNF-D-05', 'ADR-053');

it('sin digest en el paquete cae a IMAGE_TAG', function (?string $lock): void {
    expect(imagenDelSimulacro($lock, ['IMAGE_TAG' => '2.2.1', 'IMAGE_REGISTRY' => 'registro.example/kronoqr']))
        ->toBe('registro.example/kronoqr/postgres:2.2.1');
})->with([
    'sin images.lock' => [null],
    'sin la linea de postgres' => ["php sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n"],
])->group('RNF-D-05', 'ADR-053');

it('--image sigue mandando sobre todo lo anterior', function (): void {
    expect(imagenDelSimulacro('postgres '.RESTORE_DRILL_DIGEST_A."\n", ['DRILL_POSTGRES_IMAGE' => 'kronoqr/postgres:ci']))
        ->toBe('kronoqr/postgres:ci');
})->group('RNF-D-05');

it('un digest mal formado en el .env o en images.lock FALLA con 2 y nunca cae a la etiqueta en silencio', function (?string $lock, array $env): void {
    expect(imagenDelSimulacro($lock, $env + ['IMAGE_TAG' => '2.2.1'], 2))->toContain('no es un digest valido');
})->with([
    'en el .env' => [null, ['IMAGE_DIGEST_POSTGRES' => '@sha256:abc']],
    'en images.lock' => ["postgres sha256:abc\n", []],
])->group('RNF-D-05', 'ADR-053');

it('la imagen por omision de los modos container y database esta fijada por el MISMO digest que la base de la Dockerfile del producto', function (): void {
    $dockerfile = Repo::contents('infra/docker/postgres/Dockerfile');
    $script = Repo::contents('infra/scripts/restore-drill.sh');

    preg_match('/^FROM postgres:17-alpine@(sha256:[0-9a-f]{64})/m', $dockerfile, $base);

    expect($base)->toHaveCount(2)
        ->and($script)->toContain('IMAGEN_PREDETERMINADA="postgres:17-alpine@'.($base[1] ?? '').'"');
})->group('RNF-D-05', 'ADR-053');
