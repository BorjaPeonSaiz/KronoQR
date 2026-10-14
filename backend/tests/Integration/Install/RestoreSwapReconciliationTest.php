<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El intercambio de bases de `restore.sh` y su reconciliacion (V6-PL-03, RNF-D-04).
 *
 * Un `psql` de renombrado puede morir (HUP/TERM al grupo) AUNQUE su ALTER se haya
 * confirmado, o fallar de verdad. Antes de elegir rama, `intercambiar_bases` consulta
 * pg_database. Se prueba con un `psql` falso que lleva un cluster de juguete (un
 * fichero con los nombres de las bases) y se comporta segun MODOS: un modo por
 * llamada de renombrado, en orden (primero, segundo, compensacion):
 *   ok            el ALTER se aplica y sale 0
 *   falla-aplica  el ALTER se aplica pero el cliente sale con error (lo mataron)
 *   noaplica      el ALTER no se aplica y sale con error
 * CONSULTA=error hace fallar las consultas a pg_database (no se puede preguntar).
 */

/**
 * @return array{0: Process, 1: string}
 */
function restoreSwapScenario(string $modos, string $consulta = 'ok'): array
{
    $dir = sys_get_temp_dir().'/kq-restore-swap-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    mkdir($dir.'/trabajo', 0777, true);
    file_put_contents($dir.'/dbs', "fichaje\nfichaje_restore_X\n");

    // Un EJECUTABLE y no una funcion: `aislada` lo lanza con setsid, que no ve funciones.
    $psql = <<<'BASH'
#!/usr/bin/env bash
sql="${*: -1}"
case "$sql" in
*"RENAME TO"*)
  n=$(($(cat "$DIR/n" 2>/dev/null || echo 0) + 1))
  echo "$n" >"$DIR/n"
  from="$(printf '%s' "$sql" | sed 's/.*DATABASE "\([^"]*\)".*/\1/')"
  to="$(printf '%s' "$sql" | sed 's/.*TO "\([^"]*\)".*/\1/')"
  mode="$(printf '%s' "$MODOS" | cut -d, -f"$n")"
  if [ "$mode" = noaplica ]; then exit 1; fi
  sed "s/^${from}\$/${to}/" "$DIR/dbs" >"$DIR/dbs.new" && mv "$DIR/dbs.new" "$DIR/dbs"
  if [ "$mode" = falla-aplica ]; then exit 1; fi
  ;;
*"count(*) FROM pg_database"*)
  if [ "$CONSULTA" = error ]; then exit 1; fi
  name="$(printf '%s' "$sql" | sed "s/.*datname = '\([^']*\)'.*/\1/")"
  if grep -qx "$name" "$DIR/dbs"; then echo 1; else echo 0; fi
  ;;
esac
exit 0
BASH;
    file_put_contents($dir.'/bin/psql', $psql);
    chmod($dir.'/bin/psql', 0755);

    $script = 'set -euo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/restore.sh')).'; '
        .'BASE_DESTINO=fichaje; INFORME=; INFORME_TRABAJO=; TRABAJO='.escapeshellarg($dir.'/trabajo').'; BASE_NUEVA_ACTIVA=fichaje_restore_X; VENTANA_CRITICA=1; '
        .'intercambiar_bases fichaje_restore_X fichaje_pre_restore_X; echo "HECHO=${INTERCAMBIO_HECHO}"';

    $process = new Process(['bash', '-c', $script], env: [
        'KRONOQR_LANG' => 'es',
        'PATH' => $dir.'/bin:'.getenv('PATH'),
        'DIR' => $dir,
        'MODOS' => $modos,
        'CONSULTA' => $consulta,
    ], timeout: 30.0);
    $process->run();

    return [$process, trim((string) file_get_contents($dir.'/dbs'))];
}

it('primer renombrado: aplicado aunque su psql muriera => sigue; no aplicado => 3 y la base de destino intacta', function (string $modos, int $codigo): void {
    [$process, $dbs] = restoreSwapScenario($modos);

    expect($process->getExitCode())->toBe($codigo, $process->getErrorOutput().$process->getOutput());
    if ($codigo === 0) {
        expect($process->getOutput())->toContain('HECHO=1')->and($dbs)->toContain('fichaje');
    } else {
        expect($dbs)->toContain("fichaje\n");
    }
})->with([
    'ok, ok' => ['ok,ok', 0],
    'el primero falla pero se aplico' => ['falla-aplica,ok', 0],
    'el primero no se aplica' => ['noaplica', 3],
])->group('RNF-D-04', 'RNF-D-05');

it('segundo renombrado: aplicado aunque su psql muriera => hecho; no aplicado => compensa (4); compensacion fallida => 5', function (string $modos, int $codigo): void {
    [$process, $dbs] = restoreSwapScenario($modos);

    expect($process->getExitCode())->toBe($codigo, $process->getErrorOutput().$process->getOutput());
    if ($codigo === 0) {
        // La de destino es la restaurada y la anterior queda apartada.
        expect($process->getOutput())->toContain('HECHO=1')
            ->and($dbs)->toContain('fichaje_pre_restore_X');
    }
    if ($codigo === 4) {
        // Se devolvio la anterior a su nombre.
        expect($dbs)->toContain("fichaje\n");
    }
})->with([
    'el segundo falla pero se aplico' => ['ok,falla-aplica', 0],
    'el segundo no se aplica y la compensacion si' => ['ok,noaplica,ok', 4],
    'el segundo no se aplica y la compensacion tampoco' => ['ok,noaplica,noaplica', 5],
])->group('RNF-D-04', 'RNF-D-05');

it('si no se puede consultar pg_database tras un fallo, sale por el 5 con la orden de mirar el cluster y no adivina', function (string $modos): void {
    [$process] = restoreSwapScenario($modos, 'error');

    expect($process->getExitCode())->toBe(5, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('SELECT datname FROM pg_database');
})->with([
    'tras el primero' => ['falla-aplica'],
    'tras el segundo' => ['ok,falla-aplica'],
])->group('RNF-D-04', 'RNF-D-05');
