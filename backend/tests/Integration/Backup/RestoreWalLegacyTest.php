<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * `kronoqr-restore-wal` ante un segmento heredado que `kronoqr-wal-migrate` cifro en
 * sitio (`src=legacy`, ADR-049, RS-07).
 *
 * Cifrarlo no lo autentica: su contenido es el de la 2.1.0, que quien escribia en wal/
 * pudo plantar. Se reproduce solo con la misma bandera que el `.gz` en claro, y cuenta
 * como heredado (el `legacy_wal` del asiento y la metrica del simulacro), no como
 * cifrado. Un segmento sellado por el archivado normal no cambia.
 */

const RESTORE_WAL_LEGACY_SEGMENT = '000000010000000000000007';
const RESTORE_WAL_LEGACY_KEY = 'ab';

/**
 * @return array{dir: string}
 */
function restoreWalLegacySandbox(bool $legacy): array
{
    $dir = sys_get_temp_dir().'/kq-rwal-'.bin2hex(random_bytes(4));
    mkdir($dir.'/wal', 0o700, true);
    mkdir($dir.'/stats', 0o700, true);
    mkdir($dir.'/pg', 0o700, true);
    file_put_contents($dir.'/seg.gz', (string) gzencode('contenido del segmento'));

    $make = new Process(
        ['bash', '-c', 'set -euo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/lib/kqe.sh')).'; kqe_encrypt wal '.escapeshellarg(RESTORE_WAL_LEGACY_SEGMENT).' '.escapeshellarg($dir.'/wal/'.RESTORE_WAL_LEGACY_SEGMENT.'.gz.enc').($legacy ? ' legacy' : '').' <'.escapeshellarg($dir.'/seg.gz')],
        env: ['BACKUP_WAL_KEY' => str_repeat(RESTORE_WAL_LEGACY_KEY, 32)],
        timeout: 60.0,
    );
    $make->run();
    expect($make->isSuccessful())->toBeTrue($make->getErrorOutput());

    return ['dir' => $dir];
}

/**
 * @param  array<string, string>  $extra
 */
function restoreWalLegacyRun(string $dir, array $extra = []): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/docker/postgres/bin/kronoqr-restore-wal'), RESTORE_WAL_LEGACY_SEGMENT, $dir.'/pg/out'],
        env: array_merge([
            'KQE_LIB' => Repo::file('infra/scripts/lib/kqe.sh'),
            'KRONOQR_WAL_ARCHIVE_DIR' => $dir.'/wal',
            'KRONOQR_RESTORE_STATS' => $dir.'/stats',
            'BACKUP_WAL_KEY' => str_repeat(RESTORE_WAL_LEGACY_KEY, 32),
            'KRONOQR_ACCEPT_LEGACY_WAL' => '',
        ], $extra),
        timeout: 60.0,
    );
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $probe = Process::fromShellCommandline('openssl dgst -sha3-256 </dev/null');
    $probe->run();
    if (! $probe->isSuccessful()) {
        Assert::markTestSkipped('Este entorno no tiene openssl con SHA3-256 (1.1.1 o posterior).');
    }
});

it('un segmento src=legacy cifrado en sitio no se reproduce sin la bandera y no sugiere migrar para saltarla', function (): void {
    $box = restoreWalLegacySandbox(true);

    $process = restoreWalLegacyRun($box['dir']);

    expect($process->getExitCode())->toBe(200)
        ->and($process->getErrorOutput())->toContain('src=legacy')->toContain('--accept-unauthenticated')->not->toContain('kronoqr-wal-migrate')
        ->and(file_exists($box['dir'].'/pg/out'))->toBeFalse();
})->group('RL-12', 'RS-07');

it('con la bandera se reproduce y cuenta como heredado, no como cifrado', function (): void {
    $box = restoreWalLegacySandbox(true);

    $process = restoreWalLegacyRun($box['dir'], ['KRONOQR_ACCEPT_LEGACY_WAL' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('AVISO')
        ->and((string) file_get_contents($box['dir'].'/pg/out'))->toBe('contenido del segmento')
        ->and(file_exists($box['dir'].'/stats/legacy.'.RESTORE_WAL_LEGACY_SEGMENT))->toBeTrue()
        ->and(file_exists($box['dir'].'/stats/enc.'.RESTORE_WAL_LEGACY_SEGMENT))->toBeFalse();
})->group('RL-12', 'RS-07');

it('un segmento sellado por el archivado normal se reproduce sin bandera y cuenta como cifrado', function (): void {
    $box = restoreWalLegacySandbox(false);

    $process = restoreWalLegacyRun($box['dir']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((string) file_get_contents($box['dir'].'/pg/out'))->toBe('contenido del segmento')
        ->and(file_exists($box['dir'].'/stats/enc.'.RESTORE_WAL_LEGACY_SEGMENT))->toBeTrue()
        ->and(file_exists($box['dir'].'/stats/legacy.'.RESTORE_WAL_LEGACY_SEGMENT))->toBeFalse();
})->group('RL-12', 'RS-07');
