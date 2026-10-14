<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * `restore.sh` y las senales (V6-PL-03, RNF-D-04, RNF-D-05).
 *
 * Se carga el script con `source`, sin restaurar nada, y se manda la senal al
 * propio proceso con un `psql` falso que registra lo que se le pide. Lo que se
 * prueba es el REPARTO:
 *   · antes del intercambio: se elimina la base de trabajo (WITH (FORCE)) y se sale
 *     con 128+n;
 *   · dentro (los dos renombrados y el asiento): la senal solo se ANOTA;
 *   · despues (INTERCAMBIO_HECHO=1): la restauracion esta hecha. Sale con 8, o por
 *     el 6 con la orden del asiento si este quedo pendiente. Nunca por «no se ha
 *     modificado».
 * Y con la salida estandar rota (/dev/full: toda escritura falla), que es lo que ve
 * un script cuya tuberia (`| tee`) murio con el Ctrl+C.
 * El caso real, con un cluster y un SIGTERM en mitad del intercambio, lo ejecuta
 * `backup-drill.yml`.
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
        ->and($psql)->toContain('DROP DATABASE IF EXISTS "fichaje_restore_20260930T020000Z" WITH (FORCE)')
        ->and($psql)->not->toContain('RENAME')
        ->and($process->getErrorOutput())->toContain('no se ha modificado');
})->with([
    'INT' => ['INT', 130],
    'TERM' => ['TERM', 143],
    'HUP' => ['HUP', 129],
])->group('RNF-D-04', 'RNF-D-05');

it('una segunda senal durante la limpieza no la corta a medias', function (): void {
    // El psql falso reenvia otra senal al script en cada llamada: la limpieza entera
    // (DROP incluido) tiene que completarse y salir con la primera senal.
    [$dir, $process] = restoreSignalScenario(
        'psql() { printf "psql %s\n" "$*" >>'.escapeshellarg(sys_get_temp_dir().'/kq-doble-'.getmypid().'.log').'; kill -INT $$; }; '
        .'BASE_NUEVA_ACTIVA=fichaje_restore_20260930T020000Z; kill -TERM $$; sleep 5; echo NO_DEBE_LLEGAR'
    );
    $log = (string) file_get_contents(sys_get_temp_dir().'/kq-doble-'.getmypid().'.log');
    unlink(sys_get_temp_dir().'/kq-doble-'.getmypid().'.log');

    expect($process->getExitCode())->toBe(143, $process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('NO_DEBE_LLEGAR')
        ->and($log)->toContain('WITH (FORCE)');
})->group('RNF-D-04');

it('dentro de la ventana critica solo anota la senal, aunque la salida estandar este rota, y la atiende al quedar coherente con el 8', function (): void {
    // /dev/full: toda escritura en la salida falla, como en una tuberia cerrada. El
    // manejador no escribe y log/informar no hacen fallar al script.
    [, $process] = restoreSignalScenario(
        'VENTANA_CRITICA=1; exec >/dev/full; kill -TERM $$; kill -INT $$; echo "sigue" >&2; '
        .'INTERCAMBIO_HECHO=1; VENTANA_CRITICA=0; atender_senal_diferida; echo NO_DEBE_LLEGAR >&2'
    );

    expect($process->getExitCode())->toBe(8, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('sigue')
        ->and($process->getErrorOutput())->not->toContain('NO_DEBE_LLEGAR')
        ->and($process->getErrorOutput())->toContain('La restauracion SI se ha completado');
})->group('RNF-D-04', 'RNF-D-05');

it('una senal DESPUES del intercambio (p. ej. en la purga) sale con el 8 y no por «no se ha modificado»', function (string $senal): void {
    [, $process] = restoreSignalScenario(
        'INTERCAMBIO_HECHO=1; kill -'.$senal.' $$; sleep 5; echo NO_DEBE_LLEGAR'
    );

    expect($process->getExitCode())->toBe(8, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('La restauracion SI se ha completado')
        ->and($process->getErrorOutput())->not->toContain('no se ha modificado');
})->with(['INT', 'TERM', 'HUP'])->group('RNF-D-04', 'RNF-D-05');

it('con el asiento pendiente, una senal despues del intercambio termina por el 6 con la orden que lo escribe', function (): void {
    [, $process] = restoreSignalScenario(
        'INTERCAMBIO_HECHO=1; ASIENTO_PENDIENTE=1; ASIENTO_JSON="{\"kid\":\"x\"}"; kill -INT $$; sleep 5; echo NO_DEBE_LLEGAR'
    );

    expect($process->getExitCode())->toBe(6, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('compliance:record-system-event system.restored_from_backup')
        ->and($process->getErrorOutput())->toContain('NO repitas la restauracion');
})->group('RNF-D-04', 'RNF-D-05');

it('sin senal pendiente, atender_senal_diferida no hace nada', function (): void {
    [, $process] = restoreSignalScenario('VENTANA_CRITICA=0; atender_senal_diferida; echo ok');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('ok');
})->group('RNF-D-04');

it('con la salida estandar en una TUBERIA YA CERRADA (SIGPIPE real) una senal tras el intercambio sale con el 8', function (): void {
    // No /dev/full: un lector que ya salio. Sin `trap '' PIPE` la primera escritura mataria al
    // script con SIGPIPE (141) y se perderia la salida.
    $script = 'set -euo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/restore.sh')).'; '
        .'BASE_DESTINO=fichaje; INFORME=; INFORME_TRABAJO=; TRABAJO='.escapeshellarg(sys_get_temp_dir()).'/kq-pipe-'.getmypid().'; mkdir -p "$TRABAJO"; '
        .'INTERCAMBIO_HECHO=1; sleep 0.5; kill -HUP $$; sleep 5';

    // PIPESTATUS es de bash: se ejecuta con bash y no con sh.
    $process = new Process(['bash', '-c', 'bash -c '.escapeshellarg($script).' 2>/dev/null | true; exit ${PIPESTATUS[0]}'], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    expect($process->getExitCode())->toBe(8);
})->group('RNF-D-04', 'RNF-D-05');
