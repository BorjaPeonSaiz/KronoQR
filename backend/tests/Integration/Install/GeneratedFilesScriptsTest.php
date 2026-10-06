<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Los scripts del anfitrion ante los ficheros generados (ADR-045, bloque 16).
 *
 * ## Que se ejecuta
 *
 * Las cuatro piezas que el ADR pone en los scripts, cargadas con `source` (los
 * cuatro tienen la guarda de `main`) y con `docker`, `compose_current` o
 * `install` sustituidos por funciones de bash que responden lo que pide cada
 * caso. Sin Docker ni root: lo que se fija aqui es la DECISION que toma cada
 * script ante cada respuesta del motor.
 *
 *   · `install.sh` crea `BACKUP_PATH/reports/retention` 1000:1000 0750, padre
 *     primero, y registra el deshacer solo de lo que crea.
 *   · `update.sh` rescata de los contenedores de la version anterior SOLO los
 *     informes de retencion: regulares, de un nivel, con nombre exacto, sin
 *     sobrescribir y en 0640. Nunca aborta la actualizacion.
 *   · `doctor.sh` falla si falta el volumen o el montaje en uno de los tres
 *     servicios, o si la raiz no es app:app 0700; avisa (no falla) con horizon
 *     parado.
 *   · `restore.sh` anuncia en su informe los `*.file_missing` esperados.
 *
 * Lo que solo se puede ver con el motor de verdad —el volumen compartido, la
 * descarga desde `app` de lo que genero `horizon`, el rescate con
 * `docker compose cp` de un contenedor parado— lo ejercita la etapa ⑧ de la CI
 * (.github/scripts/generated-files-e2e.sh) y el job ⑧b.
 */

/**
 * Carga un script de `infra/scripts` y ejecuta un fragmento con sus funciones.
 *
 * @param  array<string, string>  $env
 */
function ficherosGeneradosBash(string $script, string $fragmento, array $env = []): Process
{
    $proceso = new Process(
        ['bash', '-c', 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/'.$script)).'; '.$fragmento],
        env: array_merge(['KRONOQR_LANG' => 'es', 'KQ_LANG' => 'es'], $env),
        timeout: 60.0,
    );
    $proceso->run();

    return $proceso;
}

function ficherosGeneradosDirectorio(string $prefijo): string
{
    // Bajo el HOME de quien ejecuta y no bajo /tmp: desde la 2.2.0 (A3-R2)
    // `kq_path_trusted` rechaza cualquier ruta con un antecesor escribible por
    // otros, sin excepcion para el bit sticky, y `update.sh` deja de usar su
    // directorio de registros si esta bajo /tmp. HOME (/var/www en el
    // contenedor, /home/runner en la CI) es del usuario y sin escritura ajena.
    $home = getenv('HOME');
    $base = \is_string($home) && $home !== '' && is_dir($home) && is_writable($home) ? $home : sys_get_temp_dir();
    $dir = $base.'/.kq-'.$prefijo.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

function ficherosGeneradosBorrar(string $dir): void
{
    /** @var iterable<string, SplFileInfo> $items */
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

function ficherosGeneradosModo(string $ruta): string
{
    clearstatcache();

    return sprintf('%o', fileperms($ruta) & 0o777);
}

/**
 * @return list<string>
 */
function ficherosGeneradosListado(string $dir): array
{
    $nombres = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($nombres);

    return $nombres;
}

/*
 * ---------------------------------------------------------------------------
 * install.sh — `BACKUP_PATH/reports/retention`
 * ---------------------------------------------------------------------------
 */

/*
 * Las pruebas no corren como root (el runner de la CI es el 1001), asi que el
 * camino de root de `kq_ensure_app_dir` se ejercita sustituyendo `id` y
 * `setpriv`: el sustituto anota con que credenciales y que orden se le pide
 * —que es la decision del script— y la ejecuta como el usuario de la prueba.
 */
const GENERATED_FILES_SCRIPTS_INSTALL_ANOTADO = <<<'BASH'
    id() { echo 0; }
    setpriv() { local IFS=' '; printf '%s\n' "$*" >>"${ANOTACIONES}"; shift 3; "$@"; }
    BASH;

/**
 * Los cinco subdirectorios de `BACKUP_PATH` que escribe el runtime, en el orden
 * en que los crea `install.sh` (el padre antes que el hijo). Desde la 2.2.0
 * (bloque 20, A3-R2) la raiz se monta en solo lectura y cada uno es un montaje
 * propio con `create_host_path: false`: si falta, `docker compose up` falla.
 *
 * @return list<string>
 */
function ficherosGeneradosSubdirectoriosDeCopias(): array
{
    return ['metrics', 'daily', 'base', 'reports', 'reports/retention'];
}

it('install.sh crea los cinco subdirectorios de BACKUP_PATH como uid 1000 y en 0750, el padre primero', function (): void {
    $dir = ficherosGeneradosDirectorio('install-retencion');
    mkdir($dir.'/copias', 0o750);

    $proceso = ficherosGeneradosBash('install.sh', GENERATED_FILES_SCRIPTS_INSTALL_ANOTADO.'; kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; ensure_backup_subdirectories',
        ['ANOTACIONES' => $dir.'/install.log'],
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and(file($dir.'/install.log', FILE_IGNORE_NEW_LINES))->toBe(array_map(
            static fn (string $sub): string => '--reuid=1000 --regid=1000 --clear-groups mkdir -m 0750 -- '.$dir.'/copias/'.$sub,
            ficherosGeneradosSubdirectoriosDeCopias(),
        ));

    foreach (ficherosGeneradosSubdirectoriosDeCopias() as $sub) {
        expect(ficherosGeneradosModo($dir.'/copias/'.$sub))->toBe('750', $sub);
    }

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-02', 'RF-PR-03', 'RL-12');

it('install.sh registra el deshacer de cada directorio que crea, y deshacerlo los retira', function (): void {
    $dir = ficherosGeneradosDirectorio('install-deshacer');
    mkdir($dir.'/copias', 0o750);

    $proceso = ficherosGeneradosBash('install.sh', GENERATED_FILES_SCRIPTS_INSTALL_ANOTADO.'; kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; ensure_backup_subdirectories; '
        .'printf "%s\n" "${ROLLBACK_STACK[@]}"; '
        .'for ((i = ${#ROLLBACK_STACK[@]} - 1; i >= 0; i--)); do eval "${ROLLBACK_STACK[i]#*|}"; done',
        ['ANOTACIONES' => $dir.'/install.log'],
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toBe(implode('', array_map(
            static fn (string $sub): string => 'directorio de informes '.$dir.'/copias/'.$sub.'|rm -rf \''.$dir.'/copias/'.$sub.'\''."\n",
            ficherosGeneradosSubdirectoriosDeCopias(),
        )))
        ->and(ficherosGeneradosListado($dir.'/copias'))->toBe([])
        ->and(is_dir($dir.'/copias'))->toBeTrue();

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-02', 'RF-PR-03');

it('install.sh no registra el deshacer de un reports/ que ya existia, y no toca sus informes', function (): void {
    // Una reinstalacion sobre un BACKUP_PATH con informes de update.sh: si un
    // fallo de la fase 4 hiciera `rm -rf` de reports/, se llevaria por delante
    // la documentacion de las actualizaciones anteriores.
    $dir = ficherosGeneradosDirectorio('install-previo');
    mkdir($dir.'/copias/reports', 0o750, true);
    file_put_contents($dir.'/copias/reports/update-20260901T020000Z.log', 'informe anterior');

    $proceso = ficherosGeneradosBash('install.sh', GENERATED_FILES_SCRIPTS_INSTALL_ANOTADO.'; kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; ensure_backup_subdirectories; '
        .'printf "%s\n" "${ROLLBACK_STACK[@]}"',
        ['ANOTACIONES' => $dir.'/install.log'],
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->not->toContain('copias/reports|')
        ->and($proceso->getOutput())->toContain('directorio de informes '.$dir.'/copias/reports/retention|')
        ->and(file_get_contents($dir.'/copias/reports/update-20260901T020000Z.log'))->toBe('informe anterior');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-02', 'RF-PR-03');

it('install.sh se detiene, con el motivo y como crearlo sin root, si no puede crear un subdirectorio', function (): void {
    // Desde la 2.2.0 es BLOQUEANTE: cada subdirectorio es un montaje con
    // `create_host_path: false` y, sin el, `docker compose up` fallaria mas
    // tarde y con peor mensaje. Mejor parar aqui y decir que hacer.
    $dir = ficherosGeneradosDirectorio('install-imposible');
    file_put_contents($dir.'/copias', 'un fichero donde deberia haber un directorio');

    $proceso = ficherosGeneradosBash('install.sh', 'kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; ensure_backup_subdirectories; echo "@@ siguio"',
    );

    expect($proceso->getExitCode())->toBe(4, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->not->toContain('@@ siguio')
        ->and($proceso->getErrorOutput())->toContain('no se ha podido crear '.$dir.'/copias/metrics')
        ->and($proceso->getErrorOutput())->toContain("sudo -u '#1000' mkdir -m 0750 -- ".$dir.'/copias/metrics');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-02', 'RF-PR-03', 'RL-12');

/*
 * Un enlace plantado por el runtime bajo BACKUP_PATH (que es del uid 1000):
 * aunque apunte a un directorio valido, ni install.sh ni update.sh lo siguen.
 * Antes, root creaba `retention` (1000:1000) alli donde apuntara `reports`.
 */
dataset('enlaces plantados bajo BACKUP_PATH', [
    'reports es un enlace' => [static fn (string $dir): bool => symlink($dir.'/objetivo', $dir.'/copias/reports')],
    'reports/retention es un enlace' => [static function (string $dir): void {
        mkdir($dir.'/copias/reports', 0o750);
        symlink($dir.'/objetivo', $dir.'/copias/reports/retention');
    }],
]);

it('install.sh no sigue un enlace plantado en reports: se detiene, deshace lo suyo y el objetivo no gana nada', function (Closure $plantar): void {
    $dir = ficherosGeneradosDirectorio('install-enlace');
    mkdir($dir.'/copias', 0o750);
    mkdir($dir.'/objetivo', 0o755);
    $plantar($dir);

    $proceso = ficherosGeneradosBash('install.sh', GENERATED_FILES_SCRIPTS_INSTALL_ANOTADO.'; kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; ensure_backup_subdirectories; echo "@@ siguio"',
        ['ANOTACIONES' => $dir.'/install.log'],
    );

    expect($proceso->getExitCode())->toBe(4, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->not->toContain('@@ siguio')
        ->and($proceso->getErrorOutput())->toContain('no se ha podido crear')
        // Lo que creo esta ejecucion antes del enlace se retira en la vuelta atras.
        ->and(is_dir($dir.'/copias/metrics'))->toBeFalse()
        ->and(is_dir($dir.'/copias/daily'))->toBeFalse()
        ->and(ficherosGeneradosListado($dir.'/objetivo'))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->with('enlaces plantados bajo BACKUP_PATH')->group('RF-PD-02', 'RF-PR-03');

/*
 * ---------------------------------------------------------------------------
 * update.sh — rescate de los informes de retencion de la version anterior (C8)
 * ---------------------------------------------------------------------------
 */

/*
 * `compose_current` sustituido. `ps -aq <servicio>` responde un identificador
 * si el servicio tiene carpeta en el origen simulado; `cp <servicio>:<ruta> <dir>`
 * deja en `<dir>` lo que haya en `ORIGEN/<servicio>/<nombre de la ruta>` con
 * `cp -a`, que como `docker cp` copia un enlace como enlace y no lo sigue. `ROTO`
 * nombra el servicio para el que el motor falla de verdad.
 */
const GENERATED_FILES_SCRIPTS_COMPOSE_SIMULADO = <<<'BASH'
    compose_current() {
      local servicio origen
      case "$1" in
      ps)
        servicio="${3}"
        [ -d "${ORIGEN}/${servicio}" ] && printf 'c0ffee-%s\n' "${servicio}"
        return 0
        ;;
      cp)
        servicio="${2%%:*}"
        origen="${ORIGEN}/${servicio}/${2##*/}"
        printf '%s\n' "$2" >>"${ANOTACIONES}"
        if [ "${servicio}" = "${ROTO:-}" ]; then
          printf 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock\n' >&2
          return 1
        fi
        if [ ! -e "${origen}" ] && [ ! -L "${origen}" ]; then
          printf 'Error response from daemon: Could not find the file %s in container\n' "${2#*:}" >&2
          return 1
        fi
        command cp -a "${origen}" "$3/"
        ;;
      esac
    }
    kq_msg_init es
    BASH;

/**
 * Prepara un origen simulado y un destino, y ejecuta `rescue_retention_reports`.
 *
 * @param  array<string, string>  $env  Lineas del `.env` actual.
 * @param  array<string, string>  $proceso  Variables para el sustituto (`ROTO`).
 * @return array{dir: string, destino: string, proceso: Process}
 */
function ficherosGeneradosRescate(string $dir, array $env = [], array $proceso = []): array
{
    $lineas = '';

    foreach ($env as $clave => $valor) {
        $lineas .= $clave.'='.$valor."\n";
    }

    file_put_contents($dir.'/actual.env', $lineas);
    touch($dir.'/anotaciones.log');

    $ejecucion = ficherosGeneradosBash('update.sh', GENERATED_FILES_SCRIPTS_COMPOSE_SIMULADO.'; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; '
        .'CURRENT_ENV='.escapeshellarg($dir.'/actual.env').'; '
        .'CURRENT_COMPOSE='.escapeshellarg($dir.'/anterior/docker-compose.yml').'; '
        .'rescue_retention_reports; echo "@@ salida=$?"',
        array_merge(['ORIGEN' => $dir.'/origen', 'ANOTACIONES' => $dir.'/anotaciones.log', 'TMPDIR' => $dir], $proceso),
    );

    return ['dir' => $dir, 'destino' => $dir.'/copias/reports/retention', 'proceso' => $ejecucion];
}

/**
 * Un informe en el contenedor simulado de un servicio.
 */
function ficherosGeneradosInforme(
    string $dir,
    string $servicio,
    string $nombre,
    string $contenido,
    int $modo = 0o644,
    string $carpetaDelContenedor = 'retention-reports',
): void {
    $carpeta = $dir.'/origen/'.$servicio.'/'.$carpetaDelContenedor;

    if (! is_dir($carpeta)) {
        mkdir($carpeta, 0o755, true);
    }

    file_put_contents($carpeta.'/'.$nombre, $contenido);
    chmod($carpeta.'/'.$nombre, $modo);
}

it('update.sh rescata de scheduler y de app los informes de retencion en 0640', function (): void {
    $dir = ficherosGeneradosDirectorio('update-rescate');
    mkdir($dir.'/copias', 0o750);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'propuesta semanal', 0o666);
    ficherosGeneradosInforme($dir, 'app', 'retencion-purga-20260301-101500.txt', 'purga con run --rm', 0o600);

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and(ficherosGeneradosListado($destino))->toBe([
            'retencion-propuesta-20260105-054000.txt',
            'retencion-purga-20260301-101500.txt',
        ])
        ->and(file_get_contents($destino.'/retencion-propuesta-20260105-054000.txt'))->toBe('propuesta semanal')
        ->and(ficherosGeneradosModo($destino.'/retencion-propuesta-20260105-054000.txt'))->toBe('640')
        ->and(ficherosGeneradosModo($destino.'/retencion-purga-20260301-101500.txt'))->toBe('640')
        ->and(ficherosGeneradosModo($destino))->toBe('750')
        ->and($proceso->getOutput())->toContain('Informes de retencion de scheduler (/var/www/html/storage/app/retention-reports) puestos a salvo en '.$destino.': 1.');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh no rescata nada que no sea un informe regular de un nivel con su nombre exacto', function (string $nombre): void {
    // El directorio de origen lo escribe el runtime: no se fia de el. Ni los ZIP
    // de la exportacion integra (datos personales caducos, ADR-045), ni un
    // enlace que apunte fuera, ni lo que haya en un subdirectorio.
    $dir = ficherosGeneradosDirectorio('update-filtro');
    mkdir($dir.'/copias', 0o750);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'el unico que vale');
    $origen = $dir.'/origen/scheduler/retention-reports';
    file_put_contents($dir.'/secreto.txt', 'fuera de la carpeta de informes');
    symlink($dir.'/secreto.txt', $origen.'/retencion-propuesta-20260112-054000.txt');
    mkdir($origen.'/retencion-purga-20260301-101500.txt');
    mkdir($origen.'/anidados');
    file_put_contents($origen.'/anidados/retencion-purga-20260302-101500.txt', 'anidado');
    file_put_contents($origen.'/kronoqr-export-2.1.0-20260901T101500Z.zip', 'datos personales');
    file_put_contents($origen.'/retencion-borrador-20260105-054000.txt', 'otro prefijo');
    file_put_contents($origen.'/retencion-propuesta-20260105-054000.txt.bak', 'otra extension');
    file_put_contents($origen.'/retencion-propuesta-.txt', 'sin marca de tiempo');

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and(ficherosGeneradosListado($destino))->toBe(['retencion-propuesta-20260105-054000.txt'])
        ->and(file_exists($destino.'/'.$nombre))->toBeFalse();

    ficherosGeneradosBorrar($dir);
})->with([
    'enlace con nombre de informe' => ['retencion-propuesta-20260112-054000.txt'],
    'directorio con nombre de informe' => ['retencion-purga-20260301-101500.txt'],
    'informe dentro de un subdirectorio' => ['retencion-purga-20260302-101500.txt'],
    'zip de la exportacion integra' => ['kronoqr-export-2.1.0-20260901T101500Z.zip'],
    'otro prefijo' => ['retencion-borrador-20260105-054000.txt'],
    'otra extension' => ['retencion-propuesta-20260105-054000.txt.bak'],
    'sin texto tras el prefijo' => ['retencion-propuesta-.txt'],
])->group('RF-PD-10', 'RF-PR-03');

it('update.sh no sobrescribe un informe que ya esta en el destino', function (): void {
    $dir = ficherosGeneradosDirectorio('update-sin-sobrescribir');
    mkdir($dir.'/copias/reports/retention', 0o750, true);
    $destino = $dir.'/copias/reports/retention';
    file_put_contents($destino.'/retencion-purga-20260301-101500.txt', 'el que ya estaba');
    ficherosGeneradosInforme($dir, 'app', 'retencion-purga-20260301-101500.txt', 'el del contenedor');

    ['proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and(file_get_contents($destino.'/retencion-purga-20260301-101500.txt'))->toBe('el que ya estaba');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh deja intacto el destino de un enlace plantado en reports/retention con nombre de informe', function (): void {
    // F1 de la revision del bloque 16. `reports/retention` es del uid 1000 y
    // `app` sigue en marcha en el paso 2: el runtime puede plantar
    // `retencion-purga-X.txt -> /etc/shadow`. Un `chmod` o un `chown` por ruta
    // hecho como root cambiaria el modo y el dueño del fichero apuntado. Aqui la
    // victima es un fichero de la prueba con modo 0600: si algo la sigue, su
    // contenido o su modo cambian.
    $dir = ficherosGeneradosDirectorio('update-enlace-plantado');
    mkdir($dir.'/copias/reports/retention', 0o750, true);
    $destino = $dir.'/copias/reports/retention';
    file_put_contents($dir.'/victima', 'contenido de la victima');
    chmod($dir.'/victima', 0o600);
    symlink($dir.'/victima', $destino.'/retencion-purga-20260301-101500.txt');
    symlink($dir.'/no-existe-todavia', $destino.'/retencion-propuesta-20260105-054000.txt');
    ficherosGeneradosInforme($dir, 'app', 'retencion-purga-20260301-101500.txt', 'el del contenedor', 0o666);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'el del contenedor', 0o666);

    ['proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and(file_get_contents($dir.'/victima'))->toBe('contenido de la victima')
        ->and(ficherosGeneradosModo($dir.'/victima'))->toBe('600')
        ->and(is_link($destino.'/retencion-purga-20260301-101500.txt'))->toBeTrue()
        // Un enlace colgante tampoco se sigue para CREAR su destino.
        ->and(file_exists($dir.'/no-existe-todavia'))->toBeFalse()
        ->and(is_link($destino.'/retencion-propuesta-20260105-054000.txt'))->toBeTrue();

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh no rescata nada si la carpeta del contenedor es un enlace o un fichero', function (Closure $plantar): void {
    // Con `ruta/.`, un `retention-reports -> /` en la capa de la 2.1.0 haria que
    // `docker cp` volcara el sistema de ficheros entero del contenedor —con el
    // montaje de BACKUP_PATH— en el temporal del anfitrion. Se copia el propio
    // enlace y se rechaza todo lo que no sea un directorio de verdad.
    $dir = ficherosGeneradosDirectorio('update-origen');
    mkdir($dir.'/copias', 0o750);
    mkdir($dir.'/fuera', 0o755);
    file_put_contents($dir.'/fuera/retencion-purga-20260301-101500.txt', 'alcanzado a traves del enlace');
    mkdir($dir.'/origen/scheduler', 0o755, true);
    $plantar($dir);

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and(ficherosGeneradosListado($destino))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->with([
    'enlace a otra carpeta' => [static fn (string $dir): bool => symlink($dir.'/fuera', $dir.'/origen/scheduler/retention-reports')],
    'fichero suelto' => [static fn (string $dir): int|false => file_put_contents($dir.'/origen/scheduler/retention-reports', 'un fichero')],
])->group('RF-PD-10', 'RF-PR-03');

it('update.sh sigue en silencio si no hay contenedores o la carpeta no existe en ellos', function (): void {
    // Lo normal en una instalacion que ya escribe en BACKUP_PATH: «no
    // encontrado» no es un fallo, y no puede asustar a quien actualiza.
    $dir = ficherosGeneradosDirectorio('update-nada');
    mkdir($dir.'/copias', 0o750);
    mkdir($dir.'/origen/app', 0o755, true);

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and($proceso->getOutput())->not->toContain('[aviso]')
        ->and(ficherosGeneradosListado($destino))->toBe([])
        ->and(file($dir.'/anotaciones.log', FILE_IGNORE_NEW_LINES))->toBe([
            'app:/var/www/html/storage/app/retention-reports',
        ]);

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh avisa y sigue si el motor falla al copiar de un servicio, y rescata los demas', function (): void {
    // Un informe no vale una vuelta atras (C8): el paso nunca aborta.
    $dir = ficherosGeneradosDirectorio('update-roto');
    mkdir($dir.'/copias', 0o750);
    ficherosGeneradosInforme($dir, 'app', 'retencion-purga-20260301-101500.txt', 'de app');
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'de scheduler');

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir, proceso: ['ROTO' => 'app']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and($proceso->getOutput())->toContain('No se han podido rescatar los informes de retencion de app.')
        ->and($proceso->getOutput())->toContain('la actualizacion sigue')
        ->and(ficherosGeneradosListado($destino))->toBe(['retencion-propuesta-20260105-054000.txt']);

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh avisa si COMPLIANCE_RETENTION_REPORT_PATH esta dentro de storage/app, y rescata de esa ruta', function (): void {
    $dir = ficherosGeneradosDirectorio('update-ruta-propia');
    mkdir($dir.'/copias', 0o750);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'de la ruta propia', carpetaDelContenedor: 'informes-propios');

    ['destino' => $destino, 'proceso' => $proceso] = ficherosGeneradosRescate($dir, [
        'COMPLIANCE_RETENTION_REPORT_PATH' => '/var/www/html/storage/app/informes-propios/',
    ]);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('COMPLIANCE_RETENTION_REPORT_PATH apunta a /var/www/html/storage/app/informes-propios, dentro de storage/app.')
        ->and($proceso->getOutput())->toContain($dir.'/copias/reports/retention')
        ->and(file($dir.'/anotaciones.log', FILE_IGNORE_NEW_LINES))->toContain('scheduler:/var/www/html/storage/app/informes-propios')
        ->and(ficherosGeneradosListado($destino))->toBe(['retencion-propuesta-20260105-054000.txt']);

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh no copia nada de los contenedores si los informes ya se escriben bajo BACKUP_PATH', function (): void {
    $dir = ficherosGeneradosDirectorio('update-ya-en-copias');
    mkdir($dir.'/copias', 0o750);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'no deberia leerse');

    ['proceso' => $proceso] = ficherosGeneradosRescate($dir, [
        'COMPLIANCE_RETENTION_REPORT_PATH' => $dir.'/copias/reports/retention',
    ]);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->not->toContain('[aviso]')
        ->and((string) file_get_contents($dir.'/anotaciones.log'))->toBe('');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh avisa y sigue si no puede crear la carpeta de destino', function (): void {
    $dir = ficherosGeneradosDirectorio('update-sin-destino');
    file_put_contents($dir.'/copias', 'un fichero donde deberia haber un directorio');
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'sin sitio');

    ['proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and($proceso->getOutput())->toContain('No se ha podido crear '.$dir.'/copias/reports/retention')
        ->and((string) file_get_contents($dir.'/anotaciones.log'))->toBe('');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10', 'RF-PR-03');

it('update.sh no sigue un enlace plantado en reports: no rescata, avisa y sigue, y el objetivo no gana nada', function (Closure $plantar): void {
    $dir = ficherosGeneradosDirectorio('update-enlace');
    mkdir($dir.'/copias', 0o750);
    mkdir($dir.'/objetivo', 0o755);
    $plantar($dir);
    ficherosGeneradosInforme($dir, 'scheduler', 'retencion-propuesta-20260105-054000.txt', 'no deberia salir');

    ['proceso' => $proceso] = ficherosGeneradosRescate($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ salida=0')
        ->and($proceso->getOutput())->toContain('No se ha podido crear '.$dir.'/copias/reports/retention')
        ->and((string) file_get_contents($dir.'/anotaciones.log'))->toBe('')
        ->and(ficherosGeneradosListado($dir.'/objetivo'))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->with('enlaces plantados bajo BACKUP_PATH')->group('RF-PD-10', 'RF-PR-03');

it('update.sh no abre su informe a traves de un reports plantado como enlace', function (): void {
    $dir = ficherosGeneradosDirectorio('update-informe-enlace');
    mkdir($dir.'/copias', 0o750);
    mkdir($dir.'/objetivo', 0o755);
    mkdir($dir.'/tmp', 0o700);
    symlink($dir.'/objetivo', $dir.'/copias/reports');

    $proceso = ficherosGeneradosBash('update.sh', 'kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; STARTED_UTC=20261002T020000Z; '
        .'SOURCE_VERSION=2.1.0; TARGET_VERSION=2.2.0; CURRENT_DIR=/srv/a; PACKAGE_DIR=/srv/b; '
        .'estado=0; open_report || estado=$?; echo "@@ abierto=${estado}"; publish_reports || true',
        ['TMPDIR' => $dir.'/tmp', 'KRONOQR_LOG_DIR' => $dir.'/registro'],
    );

    expect($proceso->getOutput())->not->toContain('@@ abierto=0')
        ->and($proceso->getOutput())->toMatch('/@@ abierto=[1-9]/')
        ->and(ficherosGeneradosListado($dir.'/objetivo'))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

it('update.sh rescata con los trabajadores ya parados y antes de recrear ningun contenedor', function (): void {
    // Antes de parar, scheduler podria escribir una propuesta durante la copia;
    // despues del paso 5, los contenedores con los informes ya no existen.
    $actualizador = Repo::contents('infra/scripts/update.sh');

    $parada = strpos($actualizador, 'compose_current stop horizon scheduler');
    $rescate = strpos($actualizador, "\n  rescue_retention_reports\n");
    $primeraRecreacion = strpos($actualizador, 'compose_new up -d');

    expect($parada)->not->toBeFalse('update.sh ya no para horizon y scheduler en el paso 2.')
        ->and($rescate)->not->toBeFalse('update.sh ya no llama a rescue_retention_reports.')
        ->and($primeraRecreacion)->not->toBeFalse('update.sh ya no levanta la version nueva con compose_new up -d.');
    expect((int) $parada)->toBeLessThan((int) $rescate)
        ->and((int) $rescate)->toBeLessThan((int) $primeraRecreacion);
})->group('RF-PD-10', 'RF-PR-03');

/*
 * ---------------------------------------------------------------------------
 * lib/fs.sh `kq_publish_as_app` y el informe de update.sh (F1)
 *
 * La pieza con la que update.sh, que corre como root, escribe en directorios
 * del uid 1000 sin hacer nada por ruta despues: crea en exclusiva (`set -C`),
 * con el modo de la mascara y, como root, a traves de `setpriv` con el uid 1000.
 * ---------------------------------------------------------------------------
 */

it('kq_ensure_app_dir responde segun lo que encuentra en la ruta', function (Closure $preparar, string $esperado): void {
    $dir = ficherosGeneradosDirectorio('asegurar');
    mkdir($dir.'/objetivo', 0o755);
    $fragmento = $preparar($dir);

    $proceso = ficherosGeneradosBash('lib/fs.sh', $fragmento.' estado=0; kq_ensure_app_dir '.escapeshellarg($dir.'/carpeta')
        .' || estado=$?; echo "@@ ${estado} creado=${KQ_DIR_CREATED}"');

    expect($proceso->getOutput())->toContain('@@ '.$esperado)
        ->and(ficherosGeneradosListado($dir.'/objetivo'))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->with([
    'no existe: la crea' => [static fn (string $dir): string => '', '0 creado=1'],
    'ya es un directorio' => [static function (string $dir): string {
        mkdir($dir.'/carpeta');

        return '';
    }, '0 creado=0'],
    'es un enlace a un directorio' => [static function (string $dir): string {
        symlink($dir.'/objetivo', $dir.'/carpeta');

        return '';
    }, '3 creado=0'],
    'es un enlace colgante' => [static function (string $dir): string {
        symlink($dir.'/objetivo/nada', $dir.'/carpeta');

        return '';
    }, '3 creado=0'],
    'es un fichero' => [static function (string $dir): string {
        file_put_contents($dir.'/carpeta', 'x');

        return '';
    }, '3 creado=0'],
    'como root y sin setpriv' => [static fn (string $dir): string => 'id() { echo 0; }; PATH=/sin-herramientas;', '2 creado=0'],
    'un enlace aparece entre crear y comprobar' => [
        static fn (string $dir): string => 'mkdir() { ln -s '.escapeshellarg($dir.'/objetivo').' "${@: -1}"; };',
        '3 creado=0',
    ],
])->group('RF-PD-02', 'RF-PD-10');

it('kq_publish_as_app crea el fichero con el modo de la mascara', function (string $mascara, string $modo): void {
    $dir = ficherosGeneradosDirectorio('publicar');
    file_put_contents($dir.'/origen', 'informe');

    $proceso = ficherosGeneradosBash('lib/fs.sh', 'kq_publish_as_app '.escapeshellarg($dir.'/origen').' '
        .escapeshellarg($dir.'/destino').' '.$mascara);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and(file_get_contents($dir.'/destino'))->toBe('informe')
        ->and(ficherosGeneradosModo($dir.'/destino'))->toBe($modo);

    ficherosGeneradosBorrar($dir);
})->with([
    'informe legible por el grupo' => ['027', '640'],
    'detalle solo para su dueño' => ['077', '600'],
])->group('RF-PD-10');

it('kq_publish_as_app no escribe si el destino ya existe, es un enlace o un enlace colgante', function (Closure $plantar): void {
    $dir = ficherosGeneradosDirectorio('publicar-exclusivo');
    file_put_contents($dir.'/origen', 'lo que se publica');
    file_put_contents($dir.'/victima', 'contenido de la victima');
    chmod($dir.'/victima', 0o600);
    $plantar($dir);

    $proceso = ficherosGeneradosBash('lib/fs.sh', 'estado=0; kq_publish_as_app '.escapeshellarg($dir.'/origen').' '
        .escapeshellarg($dir.'/destino').' 027 2>/dev/null || estado=$?; echo "@@ estado=${estado}"; '
        .'cat '.escapeshellarg($dir.'/destino').' 2>/dev/null || true');

    expect($proceso->getOutput())->not->toContain('@@ estado=0')
        ->and($proceso->getOutput())->not->toContain('lo que se publica')
        ->and(file_get_contents($dir.'/victima'))->toBe('contenido de la victima')
        ->and(ficherosGeneradosModo($dir.'/victima'))->toBe('600')
        ->and(file_exists($dir.'/creado-a-traves-del-enlace'))->toBeFalse();

    ficherosGeneradosBorrar($dir);
})->with([
    'fichero que ya estaba' => [static fn (string $dir): bool => copy($dir.'/victima', $dir.'/destino')],
    'enlace a un fichero' => [static fn (string $dir): bool => symlink($dir.'/victima', $dir.'/destino')],
    'enlace colgante' => [static fn (string $dir): bool => symlink($dir.'/creado-a-traves-del-enlace', $dir.'/destino')],
])->group('RF-PD-10');

it('kq_publish_as_app, como root, escribe a traves de setpriv con el uid 1000 y sin grupos', function (): void {
    // Sin root no se puede ejecutar el camino de verdad: se sustituyen `id` y
    // `setpriv` y se comprueba con que credenciales se pide la escritura.
    $dir = ficherosGeneradosDirectorio('publicar-root');
    file_put_contents($dir.'/origen', 'informe');

    $proceso = ficherosGeneradosBash('lib/fs.sh', 'id() { echo 0; }; '
        .'setpriv() { local IFS=" "; printf "%s\n" "${*:1:3}" >'.escapeshellarg($dir.'/setpriv.log').'; shift 3; "$@"; }; '
        .'kq_publish_as_app '.escapeshellarg($dir.'/origen').' '.escapeshellarg($dir.'/destino').' 027');

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and(file_get_contents($dir.'/setpriv.log'))->toBe("--reuid=1000 --regid=1000 --clear-groups\n")
        ->and(ficherosGeneradosModo($dir.'/destino'))->toBe('640');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

it('update.sh no cambia dueño ni modo por ruta en lo que publica en BACKUP_PATH', function (string $funcion): void {
    // La otra mitad de F1, la que una prueba de comportamiento no puede
    // provocar: el enlace plantado ENTRE la comprobacion y el `chmod`. La unica
    // garantia es que no haya `chmod` ni `chown` despues de publicar: el modo
    // sale de la mascara y el dueño del uid con el que se escribe.
    preg_match('/^'.preg_quote($funcion, '/').'\(\) \{\n(.*?)\n\}\n/ms', Repo::contents('infra/scripts/update.sh'), $cuerpo);

    expect($cuerpo[1] ?? null)->not->toBeNull('update.sh ya no define '.$funcion.'().')
        ->and((string) ($cuerpo[1] ?? ''))->not->toMatch('/^\s*[^#\n]*\b(chmod|chown)\b/m');
})->with([
    'rescue_retention_reports_from',
    'open_report',
    'publish_reports',
    'report_append',
    'detail_note',
])->group('RF-PD-10');

/**
 * `open_report` + una linea + `publish_reports`, con el nombre del informe fijo.
 *
 * @return array{dir: string, informe: string, proceso: Process}
 */
function ficherosGeneradosInformeDeActualizacion(Closure $plantar): array
{
    $dir = ficherosGeneradosDirectorio('update-informe');
    mkdir($dir.'/copias/reports', 0o750, true);
    mkdir($dir.'/tmp', 0o700);
    $plantar($dir);

    $proceso = ficherosGeneradosBash('update.sh', 'kq_msg_init es; '
        .'CFG_BACKUP_PATH='.escapeshellarg($dir.'/copias').'; STARTED_UTC=20261002T020000Z; '
        .'SOURCE_VERSION=2.1.0; TARGET_VERSION=2.2.0; CURRENT_DIR=/srv/kronoqr-2.1.0; PACKAGE_DIR=/srv/kronoqr-2.2.0; '
        .'open_report; report_append "linea del informe"; detail_note "linea del detalle"; '
        .'estado=0; publish_reports || estado=$?; echo "@@ publicado=${estado}"',
        ['TMPDIR' => $dir.'/tmp', 'KRONOQR_LOG_DIR' => $dir.'/registro'],
    );

    return ['dir' => $dir, 'informe' => $dir.'/copias/reports/update-20261002T020000Z', 'proceso' => $proceso];
}

it('update.sh publica su informe 0640 en reports y deja el detalle solo de su dueño en su directorio', function (): void {
    // El detalle puede llevar datos personales (regla dura 21): NO se publica en
    // BACKUP_PATH/reports, que escribe el runtime. Vive en el directorio de
    // registros de root (0700), como update-<UTC>.detalle.log 0600, junto a una
    // copia local del informe.
    ['dir' => $dir, 'informe' => $informe, 'proceso' => $proceso] = ficherosGeneradosInformeDeActualizacion(
        static fn (string $dir): bool => true,
    );
    $registro = $dir.'/registro/update-20261002T020000Z';

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('@@ publicado=0')
        ->and((string) file_get_contents($informe.'.log'))->toContain('linea del informe')
        ->and(ficherosGeneradosModo($informe.'.log'))->toBe('640')
        ->and(file_exists($informe.'.detalle.log'))->toBeFalse()
        ->and(ficherosGeneradosListado($dir.'/copias/reports'))->toBe(['update-20261002T020000Z.log'])
        ->and((string) file_get_contents($registro.'.detalle.log'))->toContain('linea del detalle')
        ->and(ficherosGeneradosModo($registro.'.detalle.log'))->toBe('600')
        ->and((string) file_get_contents($registro.'.log'))->toContain('linea del informe')
        ->and(ficherosGeneradosModo($registro.'.log'))->toBe('600')
        ->and(ficherosGeneradosModo($dir.'/registro'))->toBe('700');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

it('update.sh no usa un directorio de registros que sea un enlace o que no sea suyo', function (): void {
    $dir = ficherosGeneradosDirectorio('update-registro-ajeno');
    mkdir($dir.'/objetivo', 0o755);
    symlink($dir.'/objetivo', $dir.'/registro');

    $proceso = ficherosGeneradosBash('update.sh', 'KQ_UPDATE_LOG_DIR='.escapeshellarg($dir.'/registro').'; '
        .'estado=0; ensure_update_log_dir || estado=$?; echo "@@ registro=${estado}"');

    expect($proceso->getOutput())->toContain('@@ registro=1')
        ->and(ficherosGeneradosListado($dir.'/objetivo'))->toBe([]);

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

/*
 * `kq_path_trusted` con los permisos que tiene cada tramo en un servidor real. Sin
 * root no se puede dar a un directorio un dueño o un grupo ajenos: se sustituyen
 * `stat` (solo para el tramo bajo prueba) e `id -G 1000` (los grupos de la cuenta
 * uid 1000 del anfitrion), como en los demas caminos de root de este fichero. El
 * caso que importa es el `/var/log` de Ubuntu, `root:syslog 0775`: con la regla
 * «nunca escritura de grupo» update.sh se negaba a actualizar en todo Ubuntu
 * (⑧b del 03-10-2026), y doctor.sh nunca purgaba el detalle (C19).
 */
it('kq_path_trusted acepta la escritura de grupo solo en un grupo del sistema ajeno al uid 1000', function (string $dueno, string $grupo, string $modo, string $gruposDelUid1000, string $esperado): void {
    $dir = ficherosGeneradosDirectorio('tramo');
    mkdir($dir.'/log', 0o755);

    $fragmento = 'stat() { if [ "${4:-}" = '.escapeshellarg($dir.'/log').' ]; then case "$2" in %u) echo '.$dueno.';; %g) echo '.$grupo.';; %a) echo '.$modo.';; esac; else command stat "$@"; fi; }; '
        .'id() { if [ "${1:-}" = "-G" ]; then echo '.escapeshellarg($gruposDelUid1000).'; else command id "$@"; fi; }; '
        .'estado=0; kq_path_trusted '.escapeshellarg($dir.'/log/kronoqr').' || estado=$?; echo "@@ ${estado} tramo=${KQ_PATH_UNTRUSTED}"';

    $proceso = ficherosGeneradosBash('lib/fs.sh', $fragmento);

    expect($proceso->getOutput())->toContain('@@ '.$esperado);

    ficherosGeneradosBorrar($dir);
})->with([
    'root:root 0755 (el /var/log de Debian)' => ['0', '0', '755', '1000 4 24', '0 tramo='],
    'root:syslog 0775 (el /var/log de Ubuntu)' => ['0', '110', '775', '1000 4 24', '0 tramo='],
    'root:1000 0775: el grupo del runtime' => ['0', '1000', '775', '1000', '1 tramo='],
    'root:root 0777: escritura para otros' => ['0', '0', '777', '1000', '1 tramo='],
    'root:root 1777: el sticky no vale' => ['0', '0', '1777', '1000', '1 tramo='],
    'root:adm 0775 con la cuenta uid 1000 en adm' => ['0', '4', '775', '1000 4 24', '1 tramo='],
    'root:syslog 0775 sin ninguna cuenta uid 1000' => ['0', '110', '775', '', '0 tramo='],
])->group('RF-PD-10');

it('update.sh nombra el tramo que no acepta cuando el que falla es el padre del directorio de registros', function (): void {
    $dir = ficherosGeneradosDirectorio('tramo-padre');
    mkdir($dir.'/log', 0o755);

    // El padre `log` pasa a 0777 en el `stat` sustituido; el directorio aun no existe.
    $fragmento = 'stat() { if [ "${4:-}" = '.escapeshellarg($dir.'/log').' ] && [ "$2" = %a ]; then echo 777; else command stat "$@"; fi; }; '
        .'KQ_UPDATE_LOG_DIR='.escapeshellarg($dir.'/log/kronoqr').'; '
        .'estado=0; ensure_update_log_dir || estado=$?; echo "@@ registro=${estado} tramo=${KQ_PATH_UNTRUSTED}"';

    $proceso = ficherosGeneradosBash('update.sh', $fragmento);

    expect($proceso->getOutput())->toContain('@@ registro=1 tramo='.$dir.'/log')
        ->and(is_dir($dir.'/log/kronoqr'))->toBeFalse();

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

it('update.sh no sigue un enlace plantado con el nombre de su informe, y no pierde el informe', function (): void {
    // El mismo patron de F1 en `open_report`: el nombre lleva la hora de inicio
    // y reports/ es del uid 1000. Con el enlace en su sitio, la victima no
    // cambia y el informe se queda en el directorio de registros de root, con aviso.
    ['dir' => $dir, 'informe' => $informe, 'proceso' => $proceso] = ficherosGeneradosInformeDeActualizacion(
        static function (string $dir): void {
            file_put_contents($dir.'/victima', 'contenido de la victima');
            chmod($dir.'/victima', 0o600);
            symlink($dir.'/victima', $dir.'/copias/reports/update-20261002T020000Z.log');
        },
    );
    $copiaLocal = $dir.'/registro/update-20261002T020000Z.log';

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput().$proceso->getErrorOutput())->toContain('El informe de la actualizacion no se ha podido copiar a BACKUP_PATH/reports.')
        ->and($proceso->getOutput())->toContain('@@ publicado=1')
        ->and(file_get_contents($dir.'/victima'))->toBe('contenido de la victima')
        ->and(ficherosGeneradosModo($dir.'/victima'))->toBe('600')
        ->and(is_link($informe.'.log'))->toBeTrue()
        ->and((string) file_get_contents($copiaLocal))->toContain('linea del informe');

    ficherosGeneradosBorrar($dir);
})->group('RF-PD-10');

/*
 * ---------------------------------------------------------------------------
 * doctor.sh — `check_app_storage`
 * ---------------------------------------------------------------------------
 */

/*
 * `docker` y `compose_current` sustituidos. El identificador de cada contenedor
 * es el nombre de su servicio, asi que `docker inspect` responde el montaje que
 * diga `MONTAJE_<servicio>`. Cada `exec` queda anotado: con `app` parada no
 * puede haber ninguno.
 */
const GENERATED_FILES_SCRIPTS_MOTOR_SIMULADO = <<<'BASH'
    docker() {
      local variable
      case "$1" in
      volume) printf '%s' "${VOLUMEN}" ;;
      inspect)
        variable="MONTAJE_${*: -1}"
        printf '%s' "${!variable}"
        ;;
      esac
    }
    compose_current() {
      local servicio
      case "$1" in
      ps)
        if [ "$2" = "-a" ]; then printf '%s\n' "$4"; else printf 'app running\nhorizon %s\nscheduler running\n' "${HORIZON}"; fi
        ;;
      exec)
        servicio="$3"
        shift 3
        printf '%s %s\n' "${servicio}" "$1" >>"${ANOTACIONES}"
        case "${servicio}:$1" in
        horizon:sh) return 0 ;;
        app:test) [ "${COMPARTIDO}" = 1 ] ;;
        app:stat) if [ "$3" = "%U:%G" ]; then printf '%s\n' "${DUENO}"; else printf '%s\n' "${MODO}"; fi ;;
        app:du) printf '12K\t/var/www/html/storage/app\n' ;;
        esac
        ;;
      esac
    }
    kq_msg_init es
    CURRENT_COMPOSE=/srv/kronoqr/docker-compose.yml
    check_app_storage
    echo "@@ fallos=${CHECKS_FAILED} avisos=${CHECKS_WARNED}"
    BASH;

const GENERATED_FILES_SCRIPTS_MOTOR_SANO = [
    'APP_UP' => '1',
    'VOLUMEN' => 'kronoqr_app-storage',
    'MONTAJE_app' => 'volume|kronoqr_app-storage|true',
    'MONTAJE_horizon' => 'volume|kronoqr_app-storage|true',
    'MONTAJE_scheduler' => 'volume|kronoqr_app-storage|true',
    'HORIZON' => 'running',
    'COMPARTIDO' => '1',
    'DUENO' => 'app:app',
    'MODO' => '700',
];

/**
 * @param  array<string, string>  $cambios  Lo que el caso cambia del motor sano.
 * @return array{fallos: int, avisos: int, salida: string, execs: list<string>}
 */
function ficherosGeneradosDoctor(array $cambios): array
{
    $dir = ficherosGeneradosDirectorio('doctor-volumen');
    touch($dir.'/execs.log');
    $env = array_merge(GENERATED_FILES_SCRIPTS_MOTOR_SANO, $cambios, ['ANOTACIONES' => $dir.'/execs.log']);

    $proceso = ficherosGeneradosBash('doctor.sh', 'APP_UP='.$env['APP_UP'].'; '.GENERATED_FILES_SCRIPTS_MOTOR_SIMULADO, $env);
    $salida = $proceso->getOutput().$proceso->getErrorOutput();
    preg_match('/@@ fallos=(\d+) avisos=(\d+)/', $salida, $m);
    $execs = file($dir.'/execs.log', FILE_IGNORE_NEW_LINES) ?: [];
    ficherosGeneradosBorrar($dir);

    return ['fallos' => (int) ($m[1] ?? -1), 'avisos' => (int) ($m[2] ?? -1), 'salida' => $salida, 'execs' => $execs];
}

it('doctor.sh da el volumen por bueno cuando existe, los tres lo montan y la raiz es app:app 0700', function (): void {
    $resultado = ficherosGeneradosDoctor([]);

    expect($resultado['fallos'])->toBe(0, $resultado['salida'])
        ->and($resultado['avisos'])->toBe(0, $resultado['salida'])
        ->and($resultado['salida'])->toContain('Volumen de ficheros generados (app-storage) creado')
        ->and($resultado['salida'])->toContain('scheduler monta app-storage en /var/www/html/storage/app')
        ->and($resultado['salida'])->toContain('Un fichero escrito desde horizon se lee desde app')
        ->and($resultado['salida'])->toContain('La raiz del volumen (storage/app) es app:app 0700')
        ->and($resultado['salida'])->toContain('Tamano de los ficheros generados (app-storage): 12K');
})->group('RF-PD-13', 'RF-PD-14', 'RL-20');

it('doctor.sh falla una sola vez, con su remedio, ante cada defecto del volumen', function (array $cambios, string $mensaje): void {
    $resultado = ficherosGeneradosDoctor($cambios);

    expect($resultado['fallos'])->toBe(1, $resultado['salida'])
        ->and($resultado['avisos'])->toBe(0, $resultado['salida'])
        ->and($resultado['salida'])->toContain($mensaje)
        ->and($resultado['salida'])->toContain('Que hacer');
})->with([
    'falta el volumen' => [['VOLUMEN' => ''], 'Falta el volumen de ficheros generados (app-storage)'],
    'horizon sin montaje' => [['MONTAJE_horizon' => ''], 'horizon NO monta app-storage'],
    'app con una carpeta del servidor en su lugar' => [['MONTAJE_app' => 'bind||true'], 'app NO monta app-storage'],
    'scheduler en solo lectura' => [['MONTAJE_scheduler' => 'volume|kronoqr_app-storage|false'], 'scheduler NO monta app-storage'],
    'scheduler con otro volumen' => [['MONTAJE_scheduler' => 'volume|otro_volumen|true'], 'scheduler NO monta app-storage'],
    'horizon y app no ven lo mismo' => [['COMPARTIDO' => '0'], 'Un fichero escrito desde horizon NO se lee desde app'],
    'raiz legible por el grupo' => [['MODO' => '750'], 'es app:app con modo 750, y debe ser app:app 0700'],
    'raiz de root' => [['DUENO' => 'root:root'], 'es root:root con modo 700, y debe ser app:app 0700'],
])->group('RF-PD-13', 'RF-PD-14', 'RL-20');

it('doctor.sh avisa sin fallar si horizon esta parado, y sigue mirando la raiz', function (): void {
    $resultado = ficherosGeneradosDoctor(['HORIZON' => 'exited']);

    expect($resultado['fallos'])->toBe(0, $resultado['salida'])
        ->and($resultado['avisos'])->toBe(1, $resultado['salida'])
        ->and($resultado['salida'])->toContain('No se ha podido comprobar que horizon y app comparten el volumen')
        ->and($resultado['salida'])->toContain('La raiz del volumen (storage/app) es app:app 0700')
        // Sin horizon no hay prueba cruzada: app no busca ningun fichero sonda.
        ->and($resultado['execs'])->not->toContain('app test');
})->group('RF-PD-13', 'RF-PD-14', 'RL-20');

it('doctor.sh con app parada mira volumen y montajes sin entrar en ningun contenedor', function (): void {
    $resultado = ficherosGeneradosDoctor(['APP_UP' => '0', 'MONTAJE_horizon' => '']);

    expect($resultado['fallos'])->toBe(1, $resultado['salida'])
        ->and($resultado['salida'])->toContain('horizon NO monta app-storage')
        ->and($resultado['execs'])->toBe([]);
})->group('RF-PD-13', 'RF-PD-14', 'RL-20');

it('doctor.sh no entra en los contenedores para la prueba cruzada si falta un montaje', function (): void {
    // Con un montaje que no vale, la prueba cruzada y el modo de la raiz
    // hablarian de la capa del contenedor, no del volumen: se omiten.
    $resultado = ficherosGeneradosDoctor(['MONTAJE_horizon' => '']);

    expect($resultado['execs'])->toBe([]);
})->group('RF-PD-13', 'RF-PD-14', 'RL-20');

/*
 * ---------------------------------------------------------------------------
 * restore.sh — el informe anuncia los `*.file_missing` esperados (C5)
 * ---------------------------------------------------------------------------
 */

/**
 * `main --yes` de verdad, con las fases que tocan la base sustituidas.
 *
 * @return array{informe: string, proceso: Process}
 */
function ficherosGeneradosRestauracion(int $auditar, string $idioma): array
{
    $dir = ficherosGeneradosDirectorio('restore-aviso');

    $proceso = ficherosGeneradosBash('restore.sh', 'load_backup_config() { :; }; comprobar_precondiciones() { :; }; '
        .'preparar_volcado() { TRABAJO="$(mktemp -d)"; }; restaurar() { AUDITAR='.$auditar.'; }; '
        .'BACKUP_DIR_REPORTS='.escapeshellarg($dir).'; FICHERO=/var/backups/fichaje/daily/kronoqr-20260930T010203Z.dump.enc; '
        .'BASE_DESTINO=fichaje; PGHOST=postgres; PGPORT=5432; main --yes',
        ['KQ_LANG' => $idioma, 'KRONOQR_LANG' => $idioma],
    );

    $informes = glob($dir.'/restore-*.log') ?: [];
    $informe = (string) file_get_contents($informes[0] ?? '/dev/null');
    ficherosGeneradosBorrar($dir);

    return ['informe' => $informe, 'proceso' => $proceso];
}

it('restore.sh deja en el informe que los file_missing de la siguiente purga son esperados', function (): void {
    ['informe' => $informe, 'proceso' => $proceso] = ficherosGeneradosRestauracion(1, 'es');

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($informe)->toContain('app-storage) no forma parte de la copia y no se ha repuesto')
        ->and($informe)->toContain('data_export.file_missing o report_export.file_missing')
        ->and($informe)->toContain('es ESPERADO')
        ->and($informe)->toContain('BACKUP_PATH/reports/retention no se han tocado');
})->group('RF-PR-04', 'RL-15');

it('restore.sh lo anuncia en ingles cuando se le pide', function (): void {
    ['informe' => $informe] = ficherosGeneradosRestauracion(1, 'en');

    expect($informe)->toContain('data_export.file_missing or report_export.file_missing')
        ->and($informe)->toContain('is EXPECTED')
        ->and($informe)->not->toContain('ESPERADO');
})->group('RF-PR-04', 'RL-15');

it('restore.sh no lo anuncia cuando la restauracion no va a la base de la instalacion', function (): void {
    // Simulacro sobre otra base: la de la instalacion no cambia y no habra
    // file_missing que explicar. (Con --audit-by-caller, la vuelta atras de
    // update.sh, tampoco se anuncia: ver el informe del bloque 16.)
    ['informe' => $informe, 'proceso' => $proceso] = ficherosGeneradosRestauracion(0, 'es');

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($informe)->toContain('Restauracion iniciada por')
        ->and($informe)->not->toContain('file_missing');
})->group('RF-PR-04', 'RL-15');
