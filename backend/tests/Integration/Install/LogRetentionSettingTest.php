<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * LOG_RETENTION_HOURS: la retencion de Loki y Tempo es una variable del .env (RL-11).
 *
 * La misma comprobacion (`check_log_retention`, lib/checks.sh) la hacen install.sh,
 * update.sh --check-only y doctor.sh; aqui se ejecuta de verdad, cargada con `source`,
 * sobre un .env minimo. Y `warn_edited_retention` de update.sh, que le dice al cliente
 * que editaba el plazo a mano en loki.yaml o tempo.yaml que ahora lo pone en el .env.
 */

/**
 * @return array{0: Process, 1: string}
 */
function logRetentionCheck(string $env): array
{
    $dir = sys_get_temp_dir().'/kq-log-retention-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/.env', $env);

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; '
        .'check_log_retention '.escapeshellarg($dir.'/.env').'; echo "fallos=${CHECKS_FAILED} avisos=${CHECKS_WARNED}"';
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    return [$process, $dir];
}

it('LOG_RETENTION_HOURS: ausente o igual a TECHNICAL_LOG_RETENTION_DAYS x 24, bien; entero fuera de rango o basura, fallo', function (string $env, string $esperado): void {
    [$process] = logRetentionCheck($env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain($esperado);
})->with([
    'ausente' => ["APP_URL=x\n", 'fallos=0 avisos=0'],
    'vacia' => ["LOG_RETENTION_HOURS=\n", 'fallos=0 avisos=0'],
    'el de serie' => ["LOG_RETENTION_HOURS=2160\nTECHNICAL_LOG_RETENTION_DAYS=90\n", 'fallos=0 avisos=0'],
    'no es un entero' => ["LOG_RETENTION_HOURS=90d\n", 'fallos=1'],
    'basura' => ["LOG_RETENTION_HOURS=abc\n", 'fallos=1'],
    'por debajo de un dia' => ["LOG_RETENTION_HOURS=12\n", 'fallos=1'],
    'por encima de dos anos' => ["LOG_RETENTION_HOURS=20000\n", 'fallos=1'],
    'cero' => ["LOG_RETENTION_HOURS=0\n", 'fallos=1'],
])->group('RL-11', 'RF-PD-10', 'RF-PD-13');

it('LOG_RETENTION_HOURS por encima de un ano avisa y cita la minimizacion del RGPD; si no coincide con los dias, avisa', function (): void {
    [$largo] = logRetentionCheck("LOG_RETENTION_HOURS=9000\n");
    [$descuadrado] = logRetentionCheck("LOG_RETENTION_HOURS=4320\nTECHNICAL_LOG_RETENTION_DAYS=90\n");

    expect($largo->getOutput().$largo->getErrorOutput())->toContain('fallos=0 avisos=1')->toContain('art. 5.1.e')
        ->and($descuadrado->getOutput().$descuadrado->getErrorOutput())->toContain('fallos=0 avisos=1')
        ->and($descuadrado->getOutput().$descuadrado->getErrorOutput())->toContain('LOG_RETENTION_HOURS=2160');
})->group('RL-11', 'RF-PD-13');

it('update.sh avisa a quien editaba el plazo en loki.yaml o tempo.yaml: valor que tenia y variable que debe poner', function (): void {
    $dir = sys_get_temp_dir().'/kq-log-retention-old-'.bin2hex(random_bytes(4));
    mkdir($dir.'/loki', 0777, true);
    mkdir($dir.'/tempo', 0777, true);
    file_put_contents($dir.'/loki/loki.yaml', "limits_config:\n  retention_period: 4320h\n");
    file_put_contents($dir.'/tempo/tempo.yaml', "compactor:\n  compaction:\n    block_retention: 180d\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; '
        .'ENV_FILE=/opt/kronoqr-nuevo/.env; warn_edited_retention '.escapeshellarg($dir);
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $salida)
        ->and($salida)->toContain('(4320h)')->toContain('LOG_RETENTION_HOURS=4320')
        ->and($salida)->toContain('(180d)')->toContain('LOG_RETENTION_HOURS=4320 (horas) en /opt/kronoqr-nuevo/.env');
})->group('RL-11', 'RF-PD-10');

it('update.sh no avisa si el plazo del cliente es el de serie o ya usa la variable', function (): void {
    $dir = sys_get_temp_dir().'/kq-log-retention-ok-'.bin2hex(random_bytes(4));
    mkdir($dir.'/loki', 0777, true);
    mkdir($dir.'/tempo', 0777, true);
    file_put_contents($dir.'/loki/loki.yaml', "limits_config:\n  retention_period: 2160h\n");
    file_put_contents($dir.'/tempo/tempo.yaml', "compactor:\n  compaction:\n    block_retention: \${LOG_RETENTION_HOURS:-2160}h\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; warn_edited_retention '.escapeshellarg($dir);
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('LOG_RETENTION_HOURS');
})->group('RL-11', 'RF-PD-10');
