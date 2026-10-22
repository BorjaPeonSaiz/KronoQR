<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El simulacro trimestral en el servidor del cliente (RNF-D-05, RQ-09; bloque 13 de la 2.3.0).
 *
 * Cuatro cosas que no necesitan Docker ni root y que se rompen en silencio:
 *
 *   1. `schedule-drill.sh` deja el cron y el lanzador puestos, es idempotente, y el lanzador
 *      localiza la instalacion VIGENTE preguntando a Docker (V5-DV-04: la entrada de cron no
 *      lleva la version, asi que una actualizacion no la deja apuntando a un directorio retirado).
 *   2. `doctor.sh` avisa de un simulacro que falla, que tiene mas de 95 dias o que nunca se ha
 *      hecho (V5-DV-04).
 *   3. `restore-drill.sh` resuelve la imagen de la APLICACION con la misma regla de digest que la
 *      de postgres, para verificar la cadena de audit_log (V5-DV-02).
 *   4. `flip-bit.sh` cambia exactamente UN bit y es reproducible por semilla (V5-DV-01).
 */

/** @return string un directorio temporal nuevo */
function drillTempDir(): string
{
    $dir = sys_get_temp_dir().'/kq-drill-sched-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);

    return $dir;
}

function drillRemoveDir(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function drillSchedule(array $arguments, string $dir, array $env = []): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/scripts/schedule-drill.sh'), ...$arguments],
        env: array_merge([
            'KQ_DRILL_CRON_FILE' => $dir.'/cron',
            'KQ_DRILL_LAUNCHER' => $dir.'/launcher',
            'KQ_DRILL_LOG' => $dir.'/drill.log',
        ], $env),
        timeout: 30.0,
    );
    $process->run();

    return $process;
}

it('schedule-drill.sh programa el simulacro de forma idempotente y lo retira', function (): void {
    $dir = drillTempDir();

    try {
        expect(drillSchedule(['status'], $dir)->getOutput())->toContain('NO PROGRAMADO');

        $install = drillSchedule(['install'], $dir);
        expect($install->getExitCode())->toBe(0, $install->getErrorOutput())
            ->and($install->getOutput())->toContain('programado');

        $cron = (string) file_get_contents($dir.'/cron');
        expect($cron)->toContain("40 4 1 1,4,7,10 * root {$dir}/launcher >> {$dir}/drill.log 2>&1")
            // Sin version en la entrada: sobrevive a las actualizaciones lado a lado.
            ->and($cron)->not->toMatch('#/opt/kronoqr-\d#')
            // Ni la bandera de copias sin autenticar en una tarea programada (ADR-049).
            ->and($cron)->not->toContain('KRONOQR_ACCEPT_UNAUTHENTICATED')
            ->and(is_executable($dir.'/launcher'))->toBeTrue();

        $again = drillSchedule(['install'], $dir);
        expect($again->getExitCode())->toBe(0)
            ->and($again->getOutput())->toContain('Ya estaba programado');

        $custom = drillSchedule(['install', '--schedule', '0 3 2 * *'], $dir);
        expect($custom->getExitCode())->toBe(0)
            ->and((string) file_get_contents($dir.'/cron'))->toContain('0 3 2 * * root');

        expect(drillSchedule(['status'], $dir)->getOutput())->toContain('PROGRAMADO: 0 3 2 * *');

        $remove = drillSchedule(['remove'], $dir);
        expect($remove->getExitCode())->toBe(0)
            ->and(file_exists($dir.'/cron'))->toBeFalse()
            ->and(file_exists($dir.'/launcher'))->toBeFalse()
            ->and(drillSchedule(['remove'], $dir)->getOutput())->toContain('No estaba programado');
    } finally {
        drillRemoveDir($dir);
    }
})->group('RNF-D-05', 'RQ-09');

it('schedule-drill.sh rechaza una planificacion que no es de cron y un destino sin permiso, sin tocar nada', function (): void {
    $dir = drillTempDir();

    try {
        $bad = drillSchedule(['install', '--schedule', 'cada trimestre'], $dir);
        expect($bad->getExitCode())->toBe(1)
            ->and($bad->getErrorOutput())->toContain('cinco campos de cron')
            ->and(file_exists($dir.'/cron'))->toBeFalse();

        $missing = drillSchedule(['install'], $dir, ['KQ_DRILL_CRON_FILE' => $dir.'/no-existe/cron']);
        expect($missing->getExitCode())->toBe(2)
            ->and($missing->getErrorOutput())->toContain('No se ha tocado nada');
    } finally {
        drillRemoveDir($dir);
    }
})->group('RNF-D-05', 'RQ-09');

it('el lanzador entra en el directorio de la instalacion que Docker dice y ejecuta su restore-drill.sh', function (): void {
    $dir = drillTempDir();

    try {
        expect(drillSchedule(['install'], $dir)->getExitCode())->toBe(0);

        // Una instalacion falsa, y un `docker` falso que contesta con las etiquetas del contenedor.
        mkdir($dir.'/instalacion-vigente', 0o755, true);
        file_put_contents($dir.'/instalacion-vigente/restore-drill.sh', "#!/bin/sh\necho \"ejecutado en \$(pwd) con: \$*\"\n");
        chmod($dir.'/instalacion-vigente/restore-drill.sh', 0o755);
        mkdir($dir.'/bin', 0o755, true);
        file_put_contents($dir.'/bin/docker', "#!/bin/sh\necho '{$dir}/instalacion-vigente/docker-compose.yml,{$dir}/instalacion-vigente/otro.yml'\n");
        chmod($dir.'/bin/docker', 0o755);

        $run = new Process([$dir.'/launcher', '--skip-audit-chain'], env: ['PATH' => $dir.'/bin:'.getenv('PATH')], timeout: 30.0);
        $run->run();

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and($run->getOutput())->toContain("ejecutado en {$dir}/instalacion-vigente con: --skip-audit-chain");

        // Dos instalaciones (o ninguna): no adivina, dice que hacer.
        file_put_contents($dir.'/bin/docker', "#!/bin/sh\nprintf '/a/docker-compose.yml\\n/b/docker-compose.yml\\n'\n");
        $two = new Process([$dir.'/launcher'], env: ['PATH' => $dir.'/bin:'.getenv('PATH')], timeout: 30.0);
        $two->run();

        expect($two->getExitCode())->toBe(2)
            ->and($two->getErrorOutput())->toContain('no se localiza UNA instalacion');
    } finally {
        drillRemoveDir($dir);
    }
})->group('RNF-D-05', 'RQ-09');

/**
 * `check_restore_drill` de doctor.sh contra un BACKUP_PATH de juguete.
 *
 * @return array{salida: string, fallos: int, avisos: int}
 */
function drillDoctorCheck(?string $metrics, string $lang = 'es'): array
{
    $dir = drillTempDir();
    mkdir($dir.'/metrics', 0o755, true);
    if ($metrics !== null) {
        file_put_contents($dir.'/metrics/kronoqr_backup_drill.prom', $metrics);
    }
    file_put_contents($dir.'/.env', 'BACKUP_PATH='.$dir."\n");

    $scripts = Repo::file('infra/scripts');
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg($scripts.'/doctor.sh').'; '
            .'CURRENT_ENV='.escapeshellarg($dir.'/.env').'; '
            .'CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0; kq_msg_init '.$lang.'; '
            .'check_restore_drill; echo "@@ fallos=${CHECKS_FAILED} avisos=${CHECKS_WARNED}"'
        ),
        timeout: 60.0,
    );
    $process->run();
    drillRemoveDir($dir);

    $output = $process->getOutput().$process->getErrorOutput();
    preg_match('/@@ fallos=(\d+) avisos=(\d+)/', $output, $m);

    return ['salida' => $output, 'fallos' => (int) ($m[1] ?? -1), 'avisos' => (int) ($m[2] ?? -1)];
}

function drillMetrics(int $result, int $successTimestamp): string
{
    return "kronoqr_backup_restore_drill_last_result {$result}\n"
        ."kronoqr_backup_restore_drill_last_success_timestamp_seconds {$successTimestamp}\n";
}

it('doctor.sh avisa de un simulacro que nunca se ha hecho, que fallo o que tiene mas de 95 dias', function (): void {
    $none = drillDoctorCheck(null);
    expect($none['avisos'])->toBe(1)->and($none['fallos'])->toBe(0)
        ->and($none['salida'])->toContain('Todavia no consta ningun simulacro')
        ->and($none['salida'])->toContain('schedule-drill.sh install');

    $failed = drillDoctorCheck(drillMetrics(0, 0));
    expect($failed['avisos'])->toBe(1)->and($failed['fallos'])->toBe(0)
        ->and($failed['salida'])->toContain('FALLO');

    $old = drillDoctorCheck(drillMetrics(1, time() - 96 * 86400));
    expect($old['avisos'])->toBe(1)->and($old['fallos'])->toBe(0)
        ->and($old['salida'])->toContain('96 dias (mas de 95)');

    $border = drillDoctorCheck(drillMetrics(1, time() - 94 * 86400));
    expect($border['avisos'])->toBe(0)
        ->and($border['salida'])->toContain('hace 94 dias');

    $recent = drillDoctorCheck(drillMetrics(1, time() - 3600));
    expect($recent['avisos'])->toBe(0)->and($recent['fallos'])->toBe(0);
})->group('RNF-D-05', 'RQ-09');

it('doctor.sh da el aviso del simulacro tambien en ingles', function (): void {
    $old = drillDoctorCheck(drillMetrics(1, time() - 120 * 86400), 'en');

    expect($old['avisos'])->toBe(1)
        ->and($old['salida'])->toContain('is 120 days old (more than 95)')
        ->and($old['salida'])->toContain('schedule-drill.sh install');
})->group('RNF-D-05', 'RQ-09');

/**
 * `imagen_php` de restore-drill.sh con un images.lock opcional (copia en un temporal, como RestoreDrillImageTest).
 *
 * @param  array<string, string>  $env
 */
function drillPhpImage(?string $lock, array $env = [], int $exitCode = 0): string
{
    $dir = drillTempDir();
    copy(Repo::file('infra/scripts/restore-drill.sh'), $dir.'/restore-drill.sh');
    (new Process(['cp', '-r', Repo::file('infra/scripts/lib'), $dir.'/lib']))->run();
    if ($lock !== null) {
        file_put_contents($dir.'/images.lock', $lock);
    }

    $process = new Process(
        ['bash', '-c', 'set -euo pipefail; . '.escapeshellarg($dir.'/restore-drill.sh').'; imagen_php'],
        env: array_merge(['BACKUP_ENV_FILE' => '/dev/null', 'KRONOQR_LANG' => 'es'], $env),
        timeout: 30.0,
    );
    $process->run();
    drillRemoveDir($dir);

    expect($process->getExitCode())->toBe($exitCode, $process->getErrorOutput());

    return $exitCode === 0 ? $process->getOutput() : $process->getErrorOutput();
}

const RESTORE_DRILL_SCHEDULE_PHP_DIGEST = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

it('la imagen de la aplicacion del simulacro sigue la regla del paquete: digest, etiqueta o --php-image', function (): void {
    expect(drillPhpImage('php '.RESTORE_DRILL_SCHEDULE_PHP_DIGEST."\n", ['IMAGE_TAG' => '2.3.0']))
        ->toBe('ghcr.io/kronoqr/php@'.RESTORE_DRILL_SCHEDULE_PHP_DIGEST)
        ->and(drillPhpImage(null, ['IMAGE_TAG' => '2.3.0', 'IMAGE_REGISTRY' => 'registro.example/kronoqr']))
        ->toBe('registro.example/kronoqr/php:2.3.0')
        ->and(drillPhpImage('php '.RESTORE_DRILL_SCHEDULE_PHP_DIGEST."\n", ['DRILL_PHP_IMAGE' => 'kronoqr/app:ci']))
        ->toBe('kronoqr/app:ci')
        ->and(drillPhpImage('php '.RESTORE_DRILL_SCHEDULE_PHP_DIGEST."\n", ['IMAGE_DIGEST_PHP' => '', 'IMAGE_TAG' => '2.3.0']))
        ->toBe('ghcr.io/kronoqr/php:2.3.0');
})->group('RNF-D-05', 'ADR-053');

it('un digest de la aplicacion mal formado, o ninguna pista de imagen, hacen fallar el simulacro con 2 y explican que hacer', function (): void {
    expect(drillPhpImage("php sha256:abc\n", ['IMAGE_TAG' => '2.3.0'], 2))->toContain('no es un digest valido')
        ->and(drillPhpImage(null, ['IMAGE_TAG' => ''], 2))->toContain('--skip-audit-chain');
})->group('RNF-D-05', 'ADR-053');

it('restore-drill.sh verifica la cadena de audit_log tras restaurar y deja el fallo en el informe', function (): void {
    $script = Repo::contents('infra/scripts/restore-drill.sh');

    expect($script)
        ->toContain('compliance:verify-audit-chain')
        ->toContain('comprobar_cadena_auditoria || resultado=1')
        // Antes de gastar minutos restaurando, se sabe si hay con que verificar.
        ->toContain('preparar_verificacion_cadena')
        // El unico camino para no verificar es explicito y consta en el informe.
        ->toContain('--skip-audit-chain')
        ->toContain('Cadena de auditoria: OMITIDA');

    // La verificacion va despues de las dos comprobaciones de siempre.
    expect(strpos($script, 'comprobar_conteos || resultado=1'))->toBeLessThan(strpos($script, 'comprobar_cadena_auditoria || resultado=1'));
})->group('RNF-D-05', 'RS-07', 'RL-04');

it('el paquete del cliente entrega schedule-drill.sh', function (): void {
    $package = Repo::contents('infra/scripts/package.sh');

    expect($package)->toContain('infra/scripts/schedule-drill.sh')
        ->and($package)->toContain('restore-drill doctor schedule-drill');
})->group('RNF-D-05');

it('flip-bit.sh cambia exactamente un bit, siempre, y es reproducible con la misma semilla', function (): void {
    $dir = drillTempDir();

    try {
        $original = "KQE1 kind=dump kid=abcd1234 name=x\n".str_repeat("\x5a", 600);
        file_put_contents($dir.'/copia', $original);

        foreach (['header', 'body', 'trailer'] as $region) {
            foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
                file_put_contents($dir.'/copia', $original);
                $run = new Process(['bash', Repo::file('.github/scripts/flip-bit.sh'), $dir.'/copia', $region, (string) $seed], timeout: 30.0);
                $run->run();
                expect($run->getExitCode())->toBe(0, $run->getErrorOutput());

                $changed = (string) file_get_contents($dir.'/copia');
                $differences = 0;
                $xor = 0;
                for ($i = 0, $n = strlen($original); $i < $n; $i++) {
                    if ($original[$i] !== $changed[$i]) {
                        $differences++;
                        $xor = ord($original[$i]) ^ ord($changed[$i]);
                    }
                }
                // Un byte cambia (aunque el original fuera «Z»: el fallo que motivo V5-DV-01) y por un solo bit.
                expect(strlen($changed))->toBe(strlen($original))
                    ->and($differences)->toBe(1)
                    ->and($xor & ($xor - 1))->toBe(0)
                    ->and($run->getOutput())->toContain("region={$region}")->toContain("semilla={$seed}");

                // Misma semilla, mismo cambio.
                file_put_contents($dir.'/copia-2', $original);
                (new Process(['bash', Repo::file('.github/scripts/flip-bit.sh'), $dir.'/copia-2', $region, (string) $seed]))->run();
                expect((string) file_get_contents($dir.'/copia-2'))->toBe($changed);
            }
        }
    } finally {
        drillRemoveDir($dir);
    }
})->group('RNF-D-05', 'RL-12');
