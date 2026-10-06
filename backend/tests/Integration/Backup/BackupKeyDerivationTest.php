<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La jerarquia de claves de las copias, con vectores de prueba (ADR-049, C4).
 *
 * El registro de etiquetas es VERSIONADO: cambiar una (`kqwal-v1`, `kqe1-mac`,
 * `KQE1-MAC-KEY`, `KQE1-KID`) es romper todas las copias hechas con ella, y solo se
 * hace con otra version de formato. Estos vectores son lo que lo impide por
 * accidente. Se calcularon con la clave de relleno de desarrollo y se contrastaron
 * con una derivacion PBKDF2 INDEPENDIENTE (`openssl kdf`), no solo con el propio script.
 *
 * Se ejecutan `bash` y `openssl`, sin base de datos ni Redis.
 */

const BACKUP_KEY_DERIVATION_DEV_MASTER = 'kronoqr_local_dev_only_backup_key';
const BACKUP_KEY_DERIVATION_DEV_WAL_KEY = '4deec0e109388ff8c0ed3ca348abba952eef5a59c6e87906f052e8453eff69ec';
const BACKUP_KEY_DERIVATION_DEV_KMAC = '41ce46041f316587adc9a6ff6dc0f7acb18a9f51b0924e21e41e91c10e72ecf4';

/**
 * @param  array<string, string>  $env
 */
function derivationBash(string $script, array $env = []): Process
{
    $lib = Repo::file('infra/scripts/lib/kqe.sh');
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg('set -euo pipefail; . '.escapeshellarg($lib).'; '.$script),
        env: array_merge(['BACKUP_ENCRYPTION_KEY' => BACKUP_KEY_DERIVATION_DEV_MASTER], $env),
        timeout: 60.0,
    );
    $process->run();

    return $process;
}

function derivationHasSha3(): bool
{
    $probe = Process::fromShellCommandline('openssl dgst -sha3-256 </dev/null');
    $probe->run();

    return $probe->isSuccessful();
}

beforeEach(function (): void {
    if (! derivationHasSha3()) {
        Assert::markTestSkipped('Este entorno no tiene openssl con SHA3-256 (1.1.1 o posterior).');
    }
});

it('deriva la subclave del WAL de la maestra con el vector fijado', function (): void {
    $process = derivationBash('kqe_derive_wal_key "$BACKUP_ENCRYPTION_KEY"');

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(trim($process->getOutput()))->toBe(BACKUP_KEY_DERIVATION_DEV_WAL_KEY);
})->group('RL-12', 'RNF-D-02', 'RS-07');

it('deriva la clave del MAC de volcado y copia fisica con el vector fijado', function (): void {
    $process = derivationBash('_kqe_kmac_master "$BACKUP_ENCRYPTION_KEY"');

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(trim($process->getOutput()))->toBe(BACKUP_KEY_DERIVATION_DEV_KMAC);
})->group('RL-12', 'RS-07');

it('coincide con una derivacion PBKDF2 independiente (openssl kdf)', function (): void {
    $probe = Process::fromShellCommandline('openssl kdf -help 2>&1 | head -n 1');
    $probe->run();
    if (! str_contains($probe->getOutput(), 'kdf')) {
        Assert::markTestSkipped('openssl sin el subcomando `kdf` (OpenSSL 3).');
    }

    foreach (['kqwal-v1' => BACKUP_KEY_DERIVATION_DEV_WAL_KEY, 'kqe1-mac' => BACKUP_KEY_DERIVATION_DEV_KMAC] as $salt => $expected) {
        $kdf = Process::fromShellCommandline(
            'openssl kdf -keylen 32 -kdfopt digest:SHA512 -kdfopt pass:'.escapeshellarg(BACKUP_KEY_DERIVATION_DEV_MASTER)
            .' -kdfopt salt:'.$salt.' -kdfopt iter:600000 -binary PBKDF2 | od -An -tx1 | tr -d " \n"',
            timeout: 60.0,
        );
        $kdf->run();

        expect(trim($kdf->getOutput()))->toBe($expected, 'La etiqueta '.$salt.' ya no da el mismo PBKDF2.');
    }
})->group('RL-12', 'RS-07');

it('fija el kid de la clave de desarrollo, derivado de K_mac y no de la maestra', function (): void {
    // kid = SHA3-256("KQE1-KID" NUL K_mac)[0:8]. Con la subclave del WAL, K_mac(wal) = SHA3("KQE1-MAC-KEY" NUL WAL_KEY).
    $wal = derivationBash('kqe_wal_kid '.escapeshellarg(BACKUP_KEY_DERIVATION_DEV_WAL_KEY));
    expect(trim($wal->getOutput()))->toBe('209bf090');

    $dump = derivationBash('_kqe_kid_of "$(_kqe_kmac_master "$BACKUP_ENCRYPTION_KEY")"');
    expect(trim($dump->getOutput()))->toBe('776e1b96');

    // Y no es un hash directo de la maestra: seria un oraculo rapido que se salta los 600 000 pasos.
    $directo = Process::fromShellCommandline(
        'printf "KQE1-KID\\0%s" '.escapeshellarg(BACKUP_KEY_DERIVATION_DEV_MASTER).' | openssl dgst -sha3-256 -r | cut -c1-8',
    );
    $directo->run();
    expect(trim($directo->getOutput()))->not->toBe('776e1b96');
})->group('RL-12', 'RS-07');

it('mantiene la clave de desarrollo de compose.dev.yaml y la que rechaza archive-wal.sh', function (): void {
    // Una sola constante, en tres sitios: si la derivacion cambia, los tres tienen que cambiar con ella.
    expect(Repo::contents('infra/compose.dev.yaml'))->toContain(BACKUP_KEY_DERIVATION_DEV_WAL_KEY);
    expect(Repo::contents('infra/docker/postgres/bin/archive-wal.sh'))->toContain('CLAVE_DE_DESARROLLO="'.BACKUP_KEY_DERIVATION_DEV_WAL_KEY.'"');
})->group('RL-12', 'RNF-D-02');

it('acepta solo 64 hexadecimales como subclave del WAL', function (): void {
    $valid = derivationBash('kqe_wal_key_valid '.escapeshellarg(BACKUP_KEY_DERIVATION_DEV_WAL_KEY).' && echo si || echo no');
    expect(trim($valid->getOutput()))->toBe('si');

    foreach (['', 'abc', str_repeat('g', 64), str_repeat('A', 64), BACKUP_KEY_DERIVATION_DEV_WAL_KEY.'0'] as $malformed) {
        $invalid = derivationBash('kqe_wal_key_valid '.escapeshellarg($malformed).' && echo si || echo no');
        expect(trim($invalid->getOutput()))->toBe('no', 'Se ha aceptado «'.$malformed.'».');
    }
})->group('RL-12', 'RS-07');

it('no imprime la clave derivada al calcular su kid ni la deja en la linea de ordenes', function (): void {
    $process = derivationBash('kqe_derive_wal_key "$BACKUP_ENCRYPTION_KEY" >/dev/null; kqe_wal_kid '.escapeshellarg(BACKUP_KEY_DERIVATION_DEV_WAL_KEY));

    expect($process->getErrorOutput())->not->toContain(BACKUP_KEY_DERIVATION_DEV_WAL_KEY);
    expect($process->getOutput())->not->toContain(BACKUP_KEY_DERIVATION_DEV_WAL_KEY);
})->group('RL-12', 'RS-07');
