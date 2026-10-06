<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El formato KQE1 de las copias, ejecutado de verdad (ADR-049, R5-DV-04, RL-12).
 *
 * Aqui se ejercitan las funciones de `infra/scripts/lib/kqe.sh` —las mismas que usan
 * backup.sh, restore.sh, el simulacro y, copiadas a la imagen de PostgreSQL,
 * archive-wal.sh y kronoqr-restore-wal— sobre ficheros reales: ida y vuelta, y cada
 * forma de alterar una copia. La restauracion completa contra PostgreSQL la ejecuta
 * `.github/workflows/backup-drill.yml`; esto es lo que no hace falta un servidor para
 * demostrar, y lo que no se debe erosionar sin que nadie se entere:
 *
 *   · un bit cambiado en la cabecera, el cuerpo o el trailer, un fichero truncado, una
 *     clave distinta, un nombre o un tipo que no son los esperados, y la copia heredada
 *     de la 2.1.0 se rechazan o se distinguen, cada una con su codigo;
 *   · la copia es UNA lectura: el gancho que cambia el origen DESPUES de verificar no
 *     cambia lo que se descifra (TOCTOU);
 *   · el MAC del manifiesto liga el manifiesto a su volcado;
 *   · nada de la clave aparece en la salida.
 *
 * Se ejecutan `bash` y `openssl`, sin base de datos.
 */

const KQE_FORMAT_KEY = 'clave_de_prueba_de_kqe_1234567890abcdef';
const KQE_FORMAT_NAME = 'kronoqr-20261003T120000Z';

function kqeFormatHasSha3(): bool
{
    $probe = Process::fromShellCommandline('openssl dgst -sha3-256 </dev/null');
    $probe->run();

    return $probe->isSuccessful();
}

/**
 * Un directorio de trabajo privado con un fichero KQE1 recien creado.
 *
 * @return array{dir: string, file: string}
 */
function kqeFormatSandbox(string $kind = 'dump', string $name = KQE_FORMAT_NAME, string $plain = 'contenido del volcado'): array
{
    $dir = sys_get_temp_dir().'/kq-kqe-'.bin2hex(random_bytes(4));
    mkdir($dir.'/priv', 0o700, true);
    file_put_contents($dir.'/plain.bin', $plain);
    $file = $dir.'/copia.enc';

    $make = kqeFormatBash('kqe_encrypt '.escapeshellarg($kind).' '.escapeshellarg($name).' '.escapeshellarg($file).' <'.escapeshellarg($dir.'/plain.bin'), $kind === 'wal' ? ['BACKUP_WAL_KEY' => str_repeat('ab', 32)] : []);
    expect($make->isSuccessful())->toBeTrue($make->getErrorOutput());

    return ['dir' => $dir, 'file' => $file];
}

/**
 * @param  array<string, string>  $env
 */
function kqeFormatBash(string $script, array $env = []): Process
{
    $lib = Repo::file('infra/scripts/lib/kqe.sh');
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg('set -euo pipefail; . '.escapeshellarg($lib).'; '.$script),
        env: array_merge(['BACKUP_ENCRYPTION_KEY' => KQE_FORMAT_KEY], $env),
        timeout: 90.0,
    );
    $process->run();

    return $process;
}

/**
 * Abre `$file` con kqe_open y devuelve [codigo, razon, descifrado].
 *
 * @param  array<string, string>  $env
 * @return array{code: int, reason: string, plain: string}
 */
function kqeFormatOpen(string $dir, string $file, string $kind = 'dump', string $name = KQE_FORMAT_NAME, array $env = []): array
{
    $script = 'rc=0; kqe_open '.escapeshellarg($file).' '.escapeshellarg($dir.'/priv').' '.escapeshellarg($kind).' '.escapeshellarg($name).' || rc=$?;'
        .' printf "%s\n%s\n" "$rc" "$KQE_REASON"; if [ "$rc" = 0 ]; then kqe_decrypt_copy; fi';
    $process = kqeFormatBash($script, $env);
    $out = explode("\n", $process->getOutput(), 3);

    return ['code' => (int) $out[0], 'reason' => $out[1] ?? '', 'plain' => $out[2] ?? ''];
}

/** Cambia un byte de un fichero en la posicion dada (negativa: desde el final). */
function kqeFormatFlip(string $file, int $position): void
{
    $bytes = (string) file_get_contents($file);
    $index = $position < 0 ? strlen($bytes) + $position : $position;
    $bytes[$index] = $bytes[$index] === 'Z' ? 'Y' : 'Z';
    file_put_contents($file, $bytes);
}

beforeEach(function (): void {
    if (! kqeFormatHasSha3()) {
        Assert::markTestSkipped('Este entorno no tiene openssl con SHA3-256 (1.1.1 o posterior).');
    }
});

it('hace la ida y la vuelta y deja la cabecera y el trailer del formato', function (): void {
    $box = kqeFormatSandbox();
    $header = (string) strtok((string) file_get_contents($box['file']), "\n");
    $bytes = (string) file_get_contents($box['file']);

    expect($header)->toMatch('/^KQE1 kind=dump kid=[0-9a-f]{8} iter=600000 created=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z name='.KQE_FORMAT_NAME.'$/');
    expect(substr($bytes, -65))->toMatch('/^[0-9a-f]{64}\n$/');
    // El cuerpo es el `openssl enc` de la 2.1.0: Salted__ justo despues de la cabecera.
    expect(substr($bytes, strlen($header) + 1, 8))->toBe('Salted__');

    $open = kqeFormatOpen($box['dir'], $box['file']);
    expect($open['code'])->toBe(0);
    expect($open['plain'])->toBe('contenido del volcado');
    expect($bytes)->not->toContain('contenido del volcado');
})->group('RL-12', 'RS-07', 'RNF-D-02');

it('rechaza un bit cambiado en la cabecera, el cuerpo y el trailer', function (): void {
    foreach (['cabecera' => 10, 'cuerpo' => 120, 'trailer' => -5] as $zona => $position) {
        $box = kqeFormatSandbox();
        kqeFormatFlip($box['file'], $position);

        $open = kqeFormatOpen($box['dir'], $box['file']);

        expect($open['code'])->not->toBe(0, 'Un bit cambiado en '.$zona.' ha pasado la comprobacion.');
        expect($open['plain'])->toBe('', 'Se ha descifrado algo de una copia alterada ('.$zona.').');
    }
})->group('RL-12', 'RS-07');

it('distingue el MAC malo (11) de la clave distinta (13) y de la cabecera invalida (12)', function (): void {
    $box = kqeFormatSandbox();

    kqeFormatFlip($box['file'], 120);
    expect(kqeFormatOpen($box['dir'], $box['file'])['code'])->toBe(11);

    $box = kqeFormatSandbox();
    $wrongKey = kqeFormatOpen($box['dir'], $box['file'], env: ['BACKUP_ENCRYPTION_KEY' => 'otra_clave_cualquiera_larga_1234']);
    expect($wrongKey['code'])->toBe(13);
    expect($wrongKey['reason'])->toContain('clave distinta o cabecera alterada');

    $box = kqeFormatSandbox();
    file_put_contents($box['file'], (string) substr((string) file_get_contents($box['file']), 0, -20));
    expect(kqeFormatOpen($box['dir'], $box['file'])['code'])->toBe(12);
})->group('RL-12', 'RS-07');

it('rechaza un fichero renombrado y un tipo que no es el esperado, DESPUES del MAC', function (): void {
    $box = kqeFormatSandbox();

    $renamed = kqeFormatOpen($box['dir'], $box['file'], name: 'kronoqr-20240101T000000Z');
    expect($renamed['code'])->toBe(14);
    expect($renamed['reason'])->toContain('renombrado o sustituido');

    $wrongKind = kqeFormatOpen($box['dir'], $box['file'], kind: 'base');
    expect($wrongKind['code'])->toBe(14);
})->group('RL-12', 'RS-07');

it('abre el WAL con su subclave y exige el nombre del segmento', function (): void {
    $segment = '000000010000000000000007';
    $box = kqeFormatSandbox('wal', $segment, 'segmento');
    $env = ['BACKUP_WAL_KEY' => str_repeat('ab', 32)];

    $open = kqeFormatOpen($box['dir'], $box['file'], 'wal', $segment, $env);
    expect($open['code'])->toBe(0);
    expect($open['plain'])->toBe('segmento');
    expect((string) strtok((string) file_get_contents($box['file']), "\n"))->toContain('iter=10000');

    // Sin la subclave del WAL, aunque se tenga la maestra, no se abre: son claves distintas.
    $withoutKey = kqeFormatOpen($box['dir'], $box['file'], 'wal', $segment, ['BACKUP_WAL_KEY' => '']);
    expect($withoutKey['code'])->toBe(13);

    // Otro nombre de segmento: renombrado.
    expect(kqeFormatOpen($box['dir'], $box['file'], 'wal', '000000010000000000000008', $env)['code'])->toBe(14);
})->group('RL-12', 'RNF-D-02');

it('rechaza al cifrar nombres que no son de segmento ni de fichero', function (): void {
    $dir = sys_get_temp_dir().'/kq-kqe-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o700, true);
    file_put_contents($dir.'/plain.bin', 'x');

    foreach (['../etc/passwd', 'con espacio', ''] as $name) {
        $process = kqeFormatBash(
            'if kqe_encrypt wal '.escapeshellarg($name).' '.escapeshellarg($dir.'/out.enc').' <'.escapeshellarg($dir.'/plain.bin').'; then echo si; else echo no; fi',
            ['BACKUP_WAL_KEY' => str_repeat('ab', 32)],
        );
        expect(trim($process->getOutput()))->toBe('no', 'Se ha cifrado con el nombre «'.$name.'».');
    }
})->group('RL-12', 'RS-07');

it('distingue una copia heredada de la 2.1.0 (Salted__) y la descifra solo con kqe_decrypt_legacy_copy', function (): void {
    $dir = sys_get_temp_dir().'/kq-kqe-'.bin2hex(random_bytes(4));
    mkdir($dir.'/priv', 0o700, true);
    $legacy = $dir.'/heredada.enc';
    $make = Process::fromShellCommandline(
        'printf "PGDMP volcado heredado" | openssl enc -aes-256-cbc -md sha512 -pbkdf2 -iter 600000 -salt -pass env:KQ_TEST_PASS >'.escapeshellarg($legacy),
        env: ['KQ_TEST_PASS' => KQE_FORMAT_KEY],
    );
    $make->run();
    expect($make->isSuccessful())->toBeTrue($make->getErrorOutput());

    $process = kqeFormatBash(
        'rc=0; kqe_open '.escapeshellarg($legacy).' '.escapeshellarg($dir.'/priv').' dump x || rc=$?; echo "$rc"; kqe_decrypt_legacy_copy dump',
    );
    $lines = explode("\n", $process->getOutput(), 2);

    expect((int) $lines[0])->toBe(10);
    expect($lines[1])->toBe('PGDMP volcado heredado');
})->group('RL-12', 'RS-07');

it('usa la copia ya verificada aunque el origen cambie despues (TOCTOU)', function (): void {
    $box = kqeFormatSandbox();
    $hook = $box['dir'].'/hook.sh';
    file_put_contents($hook, "#!/bin/sh\nprintf 'Z' | dd of=\"\$1\" bs=1 seek=120 conv=notrunc 2>/dev/null\n");
    chmod($hook, 0o755);

    $open = kqeFormatOpen($box['dir'], $box['file'], env: ['KQE_TEST_HOOK_AFTER_VERIFY' => $hook, 'KQE_ALLOW_TEST_HOOKS' => '1']);

    // El gancho SI cambio el origen despues de verificar...
    $check = kqeFormatOpen($box['dir'], $box['file']);
    expect($check['code'])->toBe(11, 'El gancho no altero el origen: la prueba no prueba nada.');
    // ... y lo que se descifro es la copia ya verificada.
    expect($open['code'])->toBe(0);
    expect($open['plain'])->toBe('contenido del volcado');
})->group('RL-12', 'RS-07');

it('como root no ejecuta el gancho de pruebas salvo que se pida con KQE_ALLOW_TEST_HOOKS=1', function (): void {
    // La biblioteca corre como root en el anfitrion y como postgres en la imagen: un
    // binario del entorno no puede ejecutarse con esos privilegios por accidente.
    $box = kqeFormatSandbox();
    $hook = $box['dir'].'/hook.sh';
    file_put_contents($hook, "#!/bin/sh\ntouch \"\$1.gancho\"\n");
    chmod($hook, 0o755);
    // Un `id` falso que dice que somos root.
    mkdir($box['dir'].'/bin');
    file_put_contents($box['dir'].'/bin/id', "#!/bin/sh\necho 0\n");
    chmod($box['dir'].'/bin/id', 0o755);
    $path = $box['dir'].'/bin:'.(string) getenv('PATH');

    $sin = kqeFormatOpen($box['dir'], $box['file'], env: ['KQE_TEST_HOOK_AFTER_VERIFY' => $hook, 'PATH' => $path]);
    $existeSin = file_exists($box['file'].'.gancho');
    $con = kqeFormatOpen($box['dir'], $box['file'], env: ['KQE_TEST_HOOK_AFTER_VERIFY' => $hook, 'PATH' => $path, 'KQE_ALLOW_TEST_HOOKS' => '1']);

    expect($sin['code'])->toBe(0)
        ->and($existeSin)->toBeFalse('Como root se ha ejecutado el gancho sin KQE_ALLOW_TEST_HOOKS=1.')
        ->and($con['code'])->toBe(0)
        ->and(file_exists($box['file'].'.gancho'))->toBeTrue('Con KQE_ALLOW_TEST_HOOKS=1 el gancho debia ejecutarse.');
})->group('RL-12', 'RS-07');

it('autentica el manifiesto contra su volcado y rechaza el alterado', function (): void {
    $box = kqeFormatSandbox();
    $manifest = $box['dir'].'/manifest.json';
    $mac = $box['dir'].'/manifest.mac';
    file_put_contents($manifest, "{\n  \"table_count\": 2\n}\n");

    $seal = kqeFormatBash('kqe_manifest_seal '.escapeshellarg(KQE_FORMAT_NAME).' '.escapeshellarg($manifest).' '.escapeshellarg($mac));
    expect($seal->isSuccessful())->toBeTrue($seal->getErrorOutput());
    expect(trim((string) file_get_contents($mac)))->toMatch('/^[0-9a-f]{64}$/');

    $check = static fn (string $name): string => trim(kqeFormatBash(
        'kqe_open '.escapeshellarg($box['file']).' '.escapeshellarg($box['dir'].'/priv').' dump '.escapeshellarg(KQE_FORMAT_NAME)
        .' && { kqe_manifest_check '.escapeshellarg($name).' '.escapeshellarg($manifest).' '.escapeshellarg($mac).' && echo ok || echo mal; }',
    )->getOutput());

    expect($check(KQE_FORMAT_NAME))->toBe('ok');
    // Otro volcado con el mismo manifiesto: no vale.
    expect($check('kronoqr-20240101T000000Z'))->toBe('mal');
    // Un manifiesto alterado (un conteo de mas): no vale.
    file_put_contents($manifest, "{\n  \"table_count\": 9\n}\n");
    expect($check(KQE_FORMAT_NAME))->toBe('mal');
})->group('RL-12', 'RS-07');

it('no imprime la clave ni su derivada al cifrar y abrir', function (): void {
    $box = kqeFormatSandbox();
    $process = kqeFormatBash(
        'kqe_open '.escapeshellarg($box['file']).' '.escapeshellarg($box['dir'].'/priv').' dump '.escapeshellarg(KQE_FORMAT_NAME).' 2>&1; kqe_decrypt_copy 2>&1 >/dev/null',
    );

    expect($process->getOutput().$process->getErrorOutput())->not->toContain(KQE_FORMAT_KEY);
})->group('RL-12', 'RS-07');
