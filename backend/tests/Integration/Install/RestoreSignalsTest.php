<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * `restore.sh` y las senales (V6-PL-03, RNF-D-04, RNF-D-05).
 *
 * Se carga el script con `source`, sin restaurar nada, y se manda la senal al
 * propio proceso con un `psql` falso que registra lo que se le pide. Lo que se
 * prueba es el REPARTO: fuera de la ventana critica se limpia la base de trabajo
 * y se sale con 128+n; dentro (los dos renombrados y el asiento) la senal se
 * ANOTA y se atiende cuando la instalacion queda coherente, diciendo que la
 * restauracion SI se ha completado. El caso real, con un cluster y un SIGTERM en
 * mitad del intercambio, lo ejecuta `backup-drill.yml`.
 */

/**
 * @return array{0: string, 1: Process}
 */
function restoreSignalScenario(string $body): array
{
    $dir = sys_get_temp_dir().'/kq-restore-signal-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    mkdir($dir.'/trabajo', 0777, true);

    $script = 'set -euo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/restore.sh')).'; '
        .'psql() { printf "psql %s\n" "$*" >>'.escapeshellarg($dir.'/psql.log').'; }; '
        .'BASE_DESTINO=fichaje; INFORME=; INFORME_TRABAJO=; TRABAJO='.escapeshellarg($dir.'/trabajo').'; '.$body;

    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    return [$dir, $process];
}

it('fuera de la ventana critica elimina la base de trabajo y sale con 128+n, sin tocar la de destino', function (string $senal, int $codigo): void {
    [$dir, $process] = restoreSignalScenario(
        'BASE_NUEVA_ACTIVA=fichaje_restore_20260930T020000Z; kill -'.$senal.' $$; sleep 5; echo NO_DEBE_LLEGAR'
    );
    $psql = (string) file_get_contents($dir.'/psql.log');

    expect($process->getExitCode())->toBe($codigo, $process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('NO_DEBE_LLEGAR')
        ->and($psql)->toContain('DROP DATABASE IF EXISTS "fichaje_restore_20260930T020000Z"')
        ->and($psql)->not->toContain('RENAME')
        ->and($process->getErrorOutput())->toContain('no se ha modificado');
})->with([
    'INT' => ['INT', 130],
    'TERM' => ['TERM', 143],
    'HUP' => ['HUP', 129],
])->group('RNF-D-04', 'RNF-D-05');

it('dentro de la ventana critica difiere la senal y la atiende al quedar coherente, diciendo que SI se restauro', function (): void {
    [, $process] = restoreSignalScenario(
        'VENTANA_CRITICA=1; kill -TERM $$; echo "sigue_en_la_ventana=${SENAL_PENDIENTE}"; '
        .'VENTANA_CRITICA=0; atender_senal_diferida; echo NO_DEBE_LLEGAR'
    );

    expect($process->getExitCode())->toBe(143, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('sigue_en_la_ventana=TERM')
        ->and($process->getOutput())->not->toContain('NO_DEBE_LLEGAR')
        ->and($process->getErrorOutput())->toContain('La restauracion SI se ha completado');
})->group('RNF-D-04', 'RNF-D-05');

it('con el asiento pendiente no sale al terminar la ventana: main() acaba con el 6 y la orden que lo escribe', function (): void {
    [, $process] = restoreSignalScenario(
        'VENTANA_CRITICA=1; kill -INT $$; VENTANA_CRITICA=0; ASIENTO_PENDIENTE=1; atender_senal_diferida; echo "devuelve=$?"'
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('devuelve=0');
})->group('RNF-D-04', 'RNF-D-05');

it('sin senal pendiente, atender_senal_diferida no hace nada', function (): void {
    [, $process] = restoreSignalScenario('VENTANA_CRITICA=0; atender_senal_diferida; echo ok');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('ok');
})->group('RNF-D-04');
