<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El instalador ante una interrupcion: corte de SSH (SIGHUP), `kill` (SIGTERM)
 * o Ctrl+C (SIGINT) desde la fase 3 (RF-PD-02, R6-PL-01 de la reverificacion
 * 2.2.0).
 *
 * Hasta el bloque 22 install.sh solo atrapaba ERR: un corte a mitad de la fase
 * 4 dejaba el .env con secretos, contenedores y volumenes, y el reintento salia
 * con 3 («instalacion previa»). Ahora `arm_rollback_traps` arma ERR, INT, TERM y
 * HUP juntos y `disarm_rollback_traps` los retira juntos; una senal dispara la
 * misma vuelta atras que un error, UNA sola vez, y la vuelta atras no se deja
 * interrumpir.
 *
 * Se prueba SIN Docker, cargando install.sh con su guarda `BASH_SOURCE`, como
 * InstallSecretsPhaseTest. La instalacion real interrumpida con SIGTERM en la
 * fase 4 es el escenario J de la etapa ⑧ de la CI (`clean-install`).
 */

/**
 * Prologo comun: install.sh cargado sin ejecutarse y el idioma fijado.
 */
function installInterruptionPrologue(): string
{
    return 'set -Eeuo pipefail; '
        .'. '.escapeshellarg(Repo::file('infra/scripts/install.sh')).' --check-only >/dev/null 2>&1 || true; '
        .'kq_msg_init es; ';
}

function installInterruptionRun(string $script): Process
{
    $process = new Process(['bash', '-c', installInterruptionPrologue().$script], timeout: 60.0);
    $process->run();

    return $process;
}

function installInterruptionWorkDir(): string
{
    $dir = sys_get_temp_dir().'/kronoqr-interrupcion-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

function installInterruptionCleanup(string $dir): void
{
    foreach (array_diff((array) scandir($dir), ['.', '..']) as $file) {
        if (is_file($dir.'/'.$file)) {
            unlink($dir.'/'.$file);
        }
    }

    rmdir($dir);
}

it('arma ERR, INT, TERM y HUP juntos, y los retira juntos', function (): void {
    $resultado = installInterruptionRun(
        'arm_rollback_traps; echo "--armados--"; trap -p; '
        .'disarm_rollback_traps; echo "--retirados--"; trap -p'
    );

    [$armados, $retirados] = explode('--retirados--', $resultado->getOutput());

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput());

    foreach (['ERR', 'SIGINT', 'SIGTERM', 'SIGHUP'] as $senal) {
        expect($armados)->toMatch('/rollback_and_die.*\b'.$senal.'$/m', $senal.' no queda armado con la vuelta atras.')
            ->and($retirados)->not->toMatch('/\b'.$senal.'$/m', $senal.' sigue armado tras disarm_rollback_traps.');
    }

    // Las tres senales dicen CUAL fue: es lo que distingue un corte de la
    // conexion (HUP) de alguien que lo detuvo (INT o TERM).
    expect($armados)->toContain('f_interrupted INT')
        ->toContain('f_interrupted TERM')
        ->toContain('f_interrupted HUP');
})->group('RF-PD-02');

it('una senal en una fase armada deshace UNA vez y sale con 4', function (string $senal): void {
    $dir = installInterruptionWorkDir();

    // Una fase armada que no termina nunca, con subshells `$(...)` en marcha
    // como las de la fase 4: la senal tiene que dar con ellas tambien.
    $proceso = new Process(['bash', '-c', installInterruptionPrologue()
        .'ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE=/no/importa; '
        .'register_undo "marca de prueba" "echo deshecho >>'.escapeshellarg($dir.'/undo.log').'"; '
        .'arm_rollback_traps; '
        .'echo "$$" >'.escapeshellarg($dir.'/pid').'; '
        .'while :; do x="$(sleep 0.2; printf y)"; : "${x}"; sleep 0.1; done',
    ], timeout: 60.0);
    $proceso->start();

    $limite = microtime(true) + 20;
    while (! is_file($dir.'/pid') && microtime(true) < $limite) {
        usleep(50_000);
    }
    usleep(300_000);
    $pid = trim((string) @file_get_contents($dir.'/pid'));
    expect($pid)->toMatch('/^\d+$/', 'La fase simulada no llego a armarse: '.$proceso->getErrorOutput());

    (new Process(['kill', '-'.$senal, $pid]))->mustRun();
    $codigo = $proceso->wait();

    $deshechas = substr_count((string) @file_get_contents($dir.'/undo.log'), 'deshecho');

    expect($codigo)->toBe(4, $proceso->getErrorOutput())
        ->and($deshechas)->toBe(1, 'La vuelta atras se ha ejecutado '.$deshechas.' veces.')
        ->and($proceso->getErrorOutput())->toContain('senal '.$senal)
        ->toContain('tmux')
        ->toContain('Vuelta atras completada');

    installInterruptionCleanup($dir);
})->with(['TERM', 'HUP'])->group('RF-PD-02');

it('ignora una segunda senal mientras deshace', function (): void {
    $dir = installInterruptionWorkDir();
    $log = escapeshellarg($dir.'/undo.log');

    // La vuelta atras tarda (como un `docker compose down -v`) y, en medio, le
    // llegan otro TERM y un HUP: ni se corta ni empieza otra encima.
    $resultado = installInterruptionRun(
        'ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE=/no/importa; '
        .'register_undo "lenta" "sleep 1; echo deshecho >>'.$log.'"; '
        .'arm_rollback_traps; '
        .'( sleep 0.3; kill -TERM $$; sleep 0.2; kill -HUP $$ ) & '
        .'kill -TERM $$; '
        .'while :; do sleep 0.1; done'
    );

    expect($resultado->getExitCode())->toBe(4, $resultado->getErrorOutput())
        ->and(substr_count((string) @file_get_contents($dir.'/undo.log'), 'deshecho'))->toBe(1);

    installInterruptionCleanup($dir);
})->group('RF-PD-02');

it('con los traps retirados, una senal no deshace nada', function (): void {
    $dir = installInterruptionWorkDir();

    // Muere por la senal: se ejecuta dentro de otro bash para leer su 143 como
    // codigo de salida (Symfony Process lanza una excepcion si el hijo directo
    // termina por una senal). El `exit` impide que el bash de fuera se
    // sustituya por el de dentro (bash hace `exec` de la ultima orden).
    $resultado = new Process(['bash', '-c', 'bash -c "$1"; exit "$?"', '_', installInterruptionPrologue()
        .'ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE=/no/importa; '
        .'register_undo "marca" "echo deshecho >>'.escapeshellarg($dir.'/undo.log').'"; '
        .'arm_rollback_traps; disarm_rollback_traps; kill -TERM $$; sleep 5',
    ], timeout: 60.0);
    $resultado->run();

    expect($resultado->getExitCode())->toBe(143)
        ->and(file_exists($dir.'/undo.log'))->toBeFalse();

    installInterruptionCleanup($dir);
})->group('RF-PD-02');

it('la copia previa del .env nace en 0600 y completa, sin temporales', function (): void {
    // Revision de seguridad del bloque 22: la copia se escribe en un temporal
    // 0600 y se renombra, asi que la accion de deshacer nunca ve una copia a
    // medias ni hay un instante en que sea legible por otros.
    $dir = installInterruptionWorkDir();
    $env = $dir.'/.env';
    copy(Repo::file('.env.example'), $env);
    chmod($env, 0644);
    $original = (string) file_get_contents($env);

    $resultado = installInterruptionRun(
        'ENV_FILE='.escapeshellarg($env).'; PRODUCT_VERSION=2.0.0; COMPOSE_FILE=/no/importa; '
        .'phase_secrets >/dev/null 2>&1; trap - ERR'
    );

    $copia = $env.'.kronoqr-pre-install';
    clearstatcache();

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and(file_get_contents($copia))->toBe($original)
        ->and(fileperms($copia) & 0777)->toBe(0600)
        ->and(glob($copia.'.tmp.*'))->toBe([]);

    installInterruptionCleanup($dir);
})->group('RF-PD-02', 'RS-08');
