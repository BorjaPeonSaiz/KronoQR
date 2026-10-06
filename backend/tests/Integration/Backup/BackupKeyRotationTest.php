<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La rotacion de BACKUP_ENCRYPTION_KEY (R5-DV-04, ADR-049, RL-12), ejecutada de verdad.
 *
 * `docs/runbooks/rotacion-secretos.md` promete que, tras rotar la clave de cifrado, las
 * copias hechas con la anterior se siguen abriendo con BACKUP_ENCRYPTION_KEY_PREVIOUS
 * (y, para el WAL, con BACKUP_WAL_KEY_PREVIOUS). Hasta ahora nada lo comprobaba. Aqui:
 *
 *   · un volcado KQE1, una copia heredada de la 2.1.0 (`openssl enc` sin autenticar) y un
 *     segmento de WAL sellados con la clave ANTERIOR se abren con ella como `_PREVIOUS`
 *     y NO se abren sin ella (13 «clave distinta», o fallo del descifrado en la heredada);
 *   · regresion: una copia heredada cifrada con la anterior, con la actual puesta, se
 *     descifra EXACTAMENTE al claro original. Probar la actual escribiendo directamente
 *     en la salida volcaba basura delante (casi todo el cuerpo) y luego el contenido
 *     bueno: el volcado salia con mas del doble de longitud y `pg_restore` no lo leia.
 *
 * Se ejecutan `bash` y `openssl`, sin base de datos.
 */

const KEY_ROTATION_OLD = 'clave_anterior_de_la_rotacion_1234567890';
const KEY_ROTATION_NEW = 'clave_nueva_de_la_rotacion_abcdefghijklm';
const KEY_ROTATION_NAME = 'kronoqr-20261003T120000Z';
const KEY_ROTATION_WAL_NAME = '000000010000000000000007';

function keyRotationHasSha3(): bool
{
    $probe = Process::fromShellCommandline('openssl dgst -sha3-256 </dev/null');
    $probe->run();

    return $probe->isSuccessful();
}

/** Subclave del WAL: 64 hex, la repeticion de un octeto. */
function keyRotationWalKey(string $byte): string
{
    return str_repeat($byte, 32);
}

/**
 * @param  array<string, string>  $env
 */
function keyRotationBash(string $script, array $env): Process
{
    $lib = Repo::file('infra/scripts/lib/kqe.sh');
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg('set -euo pipefail; . '.escapeshellarg($lib).'; '.$script),
        env: array_merge(['BACKUP_ENCRYPTION_KEY' => '', 'BACKUP_ENCRYPTION_KEY_PREVIOUS' => '', 'BACKUP_WAL_KEY' => '', 'BACKUP_WAL_KEY_PREVIOUS' => ''], $env),
        timeout: 120.0,
    );
    $process->run();

    return $process;
}

/** Un directorio de trabajo con `priv/` (0700) y un claro dado. */
function keyRotationDir(string $plain): string
{
    $dir = sys_get_temp_dir().'/kq-rot-'.bin2hex(random_bytes(4));
    mkdir($dir.'/priv', 0o700, true);
    file_put_contents($dir.'/plain.bin', $plain);

    return $dir;
}

/** Copia heredada de la 2.1.0 cifrada con `$key`, con el ayudante de pruebas. */
function keyRotationLegacy(string $dir, string $key): string
{
    $process = new Process(
        ['bash', Repo::file('.github/scripts/forge-legacy-copy.sh'), $dir.'/heredada.enc'],
        env: ['BACKUP_ENCRYPTION_KEY' => $key],
        input: (string) file_get_contents($dir.'/plain.bin'),
        timeout: 60.0,
    );
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return $dir.'/heredada.enc';
}

/**
 * Abre una copia con kqe_open y la descifra al fichero `salida.bin`. Devuelve el codigo
 * de kqe_open, el del descifrado (99 si ni se intento) y el claro obtenido.
 *
 * @param  array<string, string>  $env
 * @return array{open: int, decrypt: int, plain: string}
 */
function keyRotationRun(string $dir, string $file, string $kind, string $name, array $env, string $legacyKind = ''): array
{
    $script = 'rc=0; kqe_open '.escapeshellarg($file).' '.escapeshellarg($dir.'/priv').' '.escapeshellarg($kind).' '.escapeshellarg($name).' || rc=$?;'
        .' dc=0;'
        .' if [ "$rc" = 0 ]; then kqe_decrypt_copy >'.escapeshellarg($dir.'/salida.bin').' || dc=$?;'
        .' elif [ "$rc" = 10 ]; then kqe_decrypt_legacy_copy '.$legacyKind.' >'.escapeshellarg($dir.'/salida.bin').' || dc=$?;'
        .' else dc=99; fi;'
        .' printf "%s %s" "$rc" "$dc"';
    $process = keyRotationBash($script, $env);
    [$open, $decrypt] = array_map('intval', explode(' ', trim($process->getOutput())) + [0, 0]);

    $plain = is_file($dir.'/salida.bin') ? (string) file_get_contents($dir.'/salida.bin') : '';
    @unlink($dir.'/salida.bin');

    return ['open' => $open, 'decrypt' => $decrypt, 'plain' => $plain];
}

beforeEach(function (): void {
    if (! keyRotationHasSha3()) {
        Assert::markTestSkipped('Este entorno no tiene openssl con SHA3-256 (1.1.1 o posterior).');
    }
});

it('abre un volcado KQE1 sellado con la clave anterior solo si se da como _PREVIOUS', function (): void {
    $dir = keyRotationDir('PGDMP volcado sellado con la clave anterior');
    $file = $dir.'/antigua.dump.enc';
    $seal = keyRotationBash(
        'kqe_encrypt dump '.escapeshellarg(KEY_ROTATION_NAME).' '.escapeshellarg($file).' <'.escapeshellarg($dir.'/plain.bin'),
        ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_OLD],
    );
    expect($seal->isSuccessful())->toBeTrue($seal->getErrorOutput());

    $with = keyRotationRun($dir, $file, 'dump', KEY_ROTATION_NAME, ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW, 'BACKUP_ENCRYPTION_KEY_PREVIOUS' => KEY_ROTATION_OLD]);
    $without = keyRotationRun($dir, $file, 'dump', KEY_ROTATION_NAME, ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW]);

    expect($with['open'])->toBe(0)
        ->and($with['plain'])->toBe('PGDMP volcado sellado con la clave anterior')
        ->and($without['open'])->toBe(13)
        ->and($without['plain'])->toBe('');
})->group('RL-12', 'RS-07');

it('abre un segmento de WAL sellado con la subclave anterior solo si se da como BACKUP_WAL_KEY_PREVIOUS', function (): void {
    $dir = keyRotationDir('segmento de wal antiguo');
    $file = $dir.'/'.KEY_ROTATION_WAL_NAME.'.enc';
    $seal = keyRotationBash(
        'kqe_encrypt wal '.escapeshellarg(KEY_ROTATION_WAL_NAME).' '.escapeshellarg($file).' <'.escapeshellarg($dir.'/plain.bin'),
        ['BACKUP_WAL_KEY' => keyRotationWalKey('cd')],
    );
    expect($seal->isSuccessful())->toBeTrue($seal->getErrorOutput());

    $with = keyRotationRun($dir, $file, 'wal', KEY_ROTATION_WAL_NAME, ['BACKUP_WAL_KEY' => keyRotationWalKey('ab'), 'BACKUP_WAL_KEY_PREVIOUS' => keyRotationWalKey('cd')]);
    $without = keyRotationRun($dir, $file, 'wal', KEY_ROTATION_WAL_NAME, ['BACKUP_WAL_KEY' => keyRotationWalKey('ab')]);

    expect($with['open'])->toBe(0)
        ->and($with['plain'])->toBe('segmento de wal antiguo')
        ->and($without['open'])->toBe(13)
        ->and($without['plain'])->toBe('');
})->group('RL-12', 'RS-07');

it('abre una copia heredada de la 2.1.0 cifrada con la clave anterior solo si se da como _PREVIOUS', function (): void {
    $dir = keyRotationDir('PGDMP '.str_repeat('volcado heredado ', 40));
    $file = keyRotationLegacy($dir, KEY_ROTATION_OLD);

    $with = keyRotationRun($dir, $file, 'dump', KEY_ROTATION_NAME, ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW, 'BACKUP_ENCRYPTION_KEY_PREVIOUS' => KEY_ROTATION_OLD], 'dump');
    $without = keyRotationRun($dir, $file, 'dump', KEY_ROTATION_NAME, ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW], 'dump');

    expect($with['open'])->toBe(10)
        ->and($with['decrypt'])->toBe(0)
        ->and($with['plain'])->toBe((string) file_get_contents($dir.'/plain.bin'))
        ->and($without['open'])->toBe(10)
        ->and($without['decrypt'])->not->toBe(0)
        ->and($without['plain'])->toBe('');
})->group('RL-12', 'RS-07');

it('con la clave actual puesta, una copia heredada cifrada con la anterior se descifra EXACTAMENTE al claro (sin basura delante)', function (): void {
    // Regresion: probar la actual escribiendo directamente en la salida dejaba delante
    // el cuerpo mal descifrado y detras el bueno. Se compara el hash y la longitud, y
    // se repite con varias claves actuales distintas porque con la clave mala el relleno
    // sale valido ~1/256 veces y devolvia 0 con basura.
    $dir = keyRotationDir('PGDMP '.random_bytes(150_000));
    $file = keyRotationLegacy($dir, KEY_ROTATION_OLD);
    $original = (string) file_get_contents($dir.'/plain.bin');

    foreach (range(1, 6) as $n) {
        $run = keyRotationRun(
            $dir,
            $file,
            'dump',
            KEY_ROTATION_NAME,
            ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW.$n, 'BACKUP_ENCRYPTION_KEY_PREVIOUS' => KEY_ROTATION_OLD],
            'dump',
        );

        expect($run['decrypt'])->toBe(0)
            ->and(strlen($run['plain']))->toBe(strlen($original), 'La salida no tiene la longitud del claro (basura delante o detras).')
            ->and(hash('sha256', $run['plain']))->toBe(hash('sha256', $original));
    }
})->group('RL-12', 'RS-07');

it('una copia fisica heredada se acepta con su firma gzip y se rechaza si el contenido no es el esperado', function (): void {
    $dir = keyRotationDir((string) gzencode(str_repeat('PGDATA ', 500)));
    $file = keyRotationLegacy($dir, KEY_ROTATION_OLD);
    $env = ['BACKUP_ENCRYPTION_KEY' => KEY_ROTATION_NEW, 'BACKUP_ENCRYPTION_KEY_PREVIOUS' => KEY_ROTATION_OLD];
    $name = 'kronoqr-base-20261003T120000Z';

    $base = keyRotationRun($dir, $file, 'base', $name, $env, 'base');
    // Pedirla como volcado: el contenido no empieza por PGDMP, asi que no es lo que se pide.
    $asDump = keyRotationRun($dir, $file, 'base', $name, $env, 'dump');

    expect($base['decrypt'])->toBe(0)
        ->and($base['plain'])->toBe((string) file_get_contents($dir.'/plain.bin'))
        ->and($asDump['decrypt'])->not->toBe(0)
        ->and($asDump['plain'])->toBe('');
})->group('RL-12', 'RS-07');
