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

it('LOG_RETENTION_HOURS por encima de 2160 h (90 dias, RL-11) avisa citando RL-11 y el RGPD; bajarlo no avisa', function (): void {
    [$largo] = logRetentionCheck("LOG_RETENTION_HOURS=9000\n");
    [$poco] = logRetentionCheck("LOG_RETENTION_HOURS=2161\n");
    [$menor] = logRetentionCheck("LOG_RETENTION_HOURS=720\nTECHNICAL_LOG_RETENTION_DAYS=90\n");
    [$limite] = logRetentionCheck("LOG_RETENTION_HOURS=2160\nTECHNICAL_LOG_RETENTION_DAYS=90\n");

    expect($largo->getOutput().$largo->getErrorOutput())->toContain('fallos=0 avisos=1')->toContain('RL-11')->toContain('art. 5.1.e')
        ->and($poco->getOutput().$poco->getErrorOutput())->toContain('fallos=0 avisos=1')
        ->and($menor->getOutput().$menor->getErrorOutput())->toContain('fallos=0 avisos=0')
        ->and($limite->getOutput().$limite->getErrorOutput())->toContain('fallos=0 avisos=0');
})->group('RL-11', 'RF-PD-13');

it('LOG_RETENTION_HOURS mayor que TECHNICAL_LOG_RETENTION_DAYS x 24 avisa y dice el valor que casa', function (): void {
    [$descuadrado] = logRetentionCheck("LOG_RETENTION_HOURS=2160\nTECHNICAL_LOG_RETENTION_DAYS=30\n");

    expect($descuadrado->getOutput().$descuadrado->getErrorOutput())->toContain('fallos=0 avisos=1')->toContain('LOG_RETENTION_HOURS=720');
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

/**
 * @return array{0: Process, 1: string}
 */
function warnEditedRetention(string $lokiValue, string $envContent): array
{
    $dir = sys_get_temp_dir().'/kq-log-retention-env-'.bin2hex(random_bytes(4));
    mkdir($dir.'/old/loki', 0777, true);
    file_put_contents($dir.'/old/loki/loki.yaml', "limits_config:\n  retention_period: {$lokiValue}\n");
    file_put_contents($dir.'/.env', $envContent);

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; '
        .'ENV_FILE='.escapeshellarg($dir.'/.env').'; warn_edited_retention '.escapeshellarg($dir.'/old');
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    return [$process, $dir.'/.env'];
}

it('un plazo MENOR que el de serie editado a mano se escribe en el .env nuevo (no se alarga lo que se redujo) y se dice', function (): void {
    [$process, $env] = warnEditedRetention('720h', "APP_URL=x\n");
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $salida)
        ->and((string) file_get_contents($env))->toContain("LOG_RETENTION_HOURS=720\n")
        ->and($salida)->toContain('MENOR')->toContain('LOG_RETENTION_HOURS=720')
        ->and(decoct((int) fileperms($env) & 0o777))->toBe('600');
})->group('RL-11', 'RF-PD-10');

it('un plazo MAYOR que el de serie solo se avisa: no se alarga la retencion sin una decision', function (): void {
    [$process, $env] = warnEditedRetention('4320h', "APP_URL=x\n");

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((string) file_get_contents($env))->not->toContain('LOG_RETENTION_HOURS')
        ->and($process->getOutput().$process->getErrorOutput())->toContain('LOG_RETENTION_HOURS=4320');
})->group('RL-11', 'RF-PD-10');

it('si el .env ya define LOG_RETENTION_HOURS no se pisa: igual, en silencio; distinta, aviso', function (): void {
    [$igual, $envIgual] = warnEditedRetention('720h', "LOG_RETENTION_HOURS=720\n");
    [$distinta, $envDistinta] = warnEditedRetention('720h', "LOG_RETENTION_HOURS=1000\n");

    expect($igual->getOutput().$igual->getErrorOutput())->not->toContain('LOG_RETENTION_HOURS')
        ->and((string) file_get_contents($envIgual))->toBe("LOG_RETENTION_HOURS=720\n")
        ->and((string) file_get_contents($envDistinta))->toBe("LOG_RETENTION_HOURS=1000\n")
        ->and($distinta->getOutput().$distinta->getErrorOutput())->toContain('720');
})->group('RL-11', 'RF-PD-10');

it('retention-guard.sh exige un entero entre 24 y 17520 antes de arrancar el binario (0 desactivaria la retencion)', function (string $valor, ?string $esperado): void {
    $process = new Process(['sh', Repo::file('infra/observability/retention-guard.sh'), 'sh', '-c', 'echo arranca=$LOG_RETENTION_HOURS'], env: ['LOG_RETENTION_HOURS' => $valor], timeout: 30.0);
    $process->run();

    if ($esperado === null) {
        expect($process->getExitCode())->toBe(64)->and($process->getOutput())->not->toContain('arranca');
    } else {
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())->and(trim($process->getOutput()))->toBe('arranca='.$esperado);
    }
})->with([
    'vacia: la de serie' => ['', '2160'],
    'el limite inferior' => ['24', '24'],
    'el limite superior' => ['17520', '17520'],
    'ceros a la izquierda: decimal, no octal' => ['0100', '100'],
    'cero' => ['0', null],
    'por debajo' => ['23', null],
    'por encima' => ['17521', null],
    'texto' => ['abc', null],
    'con sufijo' => ['90d', null],
    'con salto de linea (inyeccion de claves)' => ["2160\nretention_enabled: false", null],
    'enorme' => ['99999999999', null],
])->group('RL-11', 'RF-PD-10');
