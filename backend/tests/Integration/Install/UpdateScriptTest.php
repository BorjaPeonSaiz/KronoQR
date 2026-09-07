<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El actualizador, EJECUTADO de verdad (RF-PD-10, RQ-11).
 *
 * ## Que se prueba aqui y que no
 *
 * Lo que decide si el servidor de un hotel queda tocado o no sin necesitar
 * Docker ni root: la matriz de versiones (ventana soportada, cadena de
 * intermedias, a que version ir primero), el reparto de migraciones por
 * version, los codigos de salida de uso y de precondicion, y que la licencia NO
 * es una precondicion (regla dura 15). La actualizacion completa —copia,
 * migraciones, arranque, vuelta atras— la prueba el job `update` de la etapa
 * ⑧ de la CI contra una instalacion real de la version anterior.
 *
 * ## Por que se carga el script en vez de solo invocarlo
 *
 * `update.sh` termina con la misma guarda que `install.sh`, asi que se puede
 * hacer `source` de el sin actualizar nada y ejercitar sus funciones puras con
 * una matriz sintetica: es la unica forma de probar «la vigente y las dos
 * anteriores» mientras solo existen dos versiones publicadas.
 */

/**
 * @param  list<string>  $argumentos
 * @param  array<string, string>  $env
 */
function ejecutarActualizador(array $argumentos, array $env = []): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/scripts/update.sh'), ...$argumentos],
        env: array_merge(['KRONOQR_LANG' => 'es'], $env),
        timeout: 120.0,
    );
    $process->run();

    return $process;
}

/**
 * Carga update.sh sin ejecutarlo y corre un fragmento con sus funciones.
 */
function bashConElActualizador(string $script): Process
{
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '.$script
        ),
        timeout: 60.0,
    );
    $process->run();

    return $process;
}

/**
 * Un paquete de entrega minimo (sin .env: asi llega al cliente).
 *
 * @return array{dir: string, compose: string}
 */
function paqueteDeActualizacion(): array
{
    $dir = sys_get_temp_dir().'/kronoqr-update-'.bin2hex(random_bytes(6));

    mkdir($dir, 0o755, true);
    copy(Repo::file('infra/compose.prod.yaml'), $dir.'/docker-compose.yml');
    copy(Repo::file('.env.example'), $dir.'/.env.example');
    copy(Repo::file('VERSION'), $dir.'/VERSION');
    copy(Repo::file('infra/versions.txt'), $dir.'/versions.txt');

    return ['dir' => $dir, 'compose' => $dir.'/docker-compose.yml'];
}

function borrarPaqueteDeActualizacion(string $dir): void
{
    /** @var iterable<string, SplFileInfo> $items */
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/**
 * Una matriz de versiones sintetica, escrita en un fichero temporal.
 */
function matrizSintetica(string $contenido): string
{
    $file = sys_get_temp_dir().'/kronoqr-versions-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($file, $contenido);

    return $file;
}

it('sale con 1 y no toca nada ante una opcion que no existe', function (): void {
    $resultado = ejecutarActualizador(['--esta-opcion-no-existe']);

    expect($resultado->getExitCode())->toBe(1)
        ->and($resultado->getErrorOutput())->toContain('update.sh --help');
})->group('RF-PD-10', 'RQ-11');

it('dice la version del paquete y la ayuda en los dos idiomas', function (): void {
    $version = trim((string) file_get_contents(Repo::file('VERSION')));

    expect(trim(ejecutarActualizador(['--version'])->getOutput()))->toBe($version);

    $es = ejecutarActualizador(['--help'])->getOutput();
    $en = ejecutarActualizador(['--lang', 'en', '--help'])->getOutput();

    expect($es)->toContain('Codigos de salida')
        // Lo que un cliente tiene que leer antes de pulsar: no hay bandera para
        // omitir la copia, y el 6 no existe aqui.
        ->and($es)->toContain('bloqueante')
        ->and($en)->toContain('Exit codes')
        ->and($en)->not->toContain('Codigos de salida');
})->group('RF-PD-10');

it('publica desde que versiones se puede actualizar a la de este paquete', function (): void {
    // La matriz del §11.6.5 es un dato del paquete y se puede consultar sin
    // Docker: es lo que usa la CI para saber que version anterior instalar.
    $version = trim((string) file_get_contents(Repo::file('VERSION')));
    $resultado = ejecutarActualizador(['--supported-sources']);

    expect($resultado->getExitCode())->toBe(0);

    $lineas = array_values(array_filter(explode("\n", trim($resultado->getOutput()))));

    expect($lineas)->not->toBeEmpty();

    foreach ($lineas as $linea) {
        expect($linea)->toMatch('/^\d+\.\d+\.\d+/')
            ->and($linea)->not->toBe($version);
    }

    // Y la cadena desde la version en desarrollo hacia si misma es vacia: no
    // hay salto que dar.
    expect(ejecutarActualizador(['--chain', $version])->getOutput())->toContain('ninguna');
})->group('RF-PD-10');

it('compara versiones SemVer como manda la especificacion', function (string $a, string $b, string $esperado): void {
    $resultado = bashConElActualizador(sprintf('kq_semver_compare %s %s', escapeshellarg($a), escapeshellarg($b)));

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toBe($esperado);
})->with([
    'menor anterior' => ['2.0.0', '2.1.0', '-1'],
    'iguales' => ['2.1.0', '2.1.0', '0'],
    'parche posterior con preliberacion' => ['2.1.1-ci', '2.1.0', '1'],
    'preliberacion antes que su final' => ['2.1.0-rc.1', '2.1.0', '-1'],
    'identificadores numericos por valor' => ['2.1.0-rc.10', '2.1.0-rc.9', '1'],
    'prefijo mas corto es menor' => ['2.1.0-alpha', '2.1.0-alpha.1', '-1'],
    'alfanumerico en orden ascii' => ['2.1.0-beta', '2.1.0-alpha', '1'],
    'mayor manda sobre todo' => ['10.0.0', '9.9.9', '1'],
    'los metadatos no cuentan' => ['2.1.0+b1', '2.1.0', '0'],
])->group('RF-PD-10');

it('soporta la menor vigente y las dos anteriores, y dice a que version ir primero', function (): void {
    // Cinco versiones publicadas: desde 2.4.0 se llega directamente desde la
    // 2.2 y la 2.3; la 2.0 y la 2.1 tienen que pasar antes por la 2.2.
    $matriz = matrizSintetica(<<<'TXT'
        # sintetica
        2.0.0 -
        2.1.0 -
        2.2.0 -
        2.3.0 -
        2.4.0 *
        TXT);

    $resultado = bashConElActualizador(sprintf(
        'kq_versions_load %s; '
        .'echo "sources: $(kq_supported_sources 2.4.0 | tr "\n" " ")"; '
        .'echo "hop-2.0: $(kq_first_hop 2.0.0 2.4.0)"; '
        .'echo "hop-2.1: $(kq_first_hop 2.1.0 2.4.0)"; '
        .'echo "chain: $(kq_upgrade_chain 2.0.0 2.3.0 | tr "\n" " ")"; '
        .'echo "major: $(kq_first_hop 1.2.0 2.4.0)"; '
        .'kq_is_supported_source 2.2.0 2.4.0 && echo "2.2 ok"; '
        .'kq_is_supported_source 2.1.0 2.4.0 || echo "2.1 no"',
        escapeshellarg($matriz),
    ));
    unlink($matriz);

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('sources: 2.2.0 2.3.0')
        ->and($resultado->getOutput())->toContain('hop-2.0: 2.2.0')
        ->and($resultado->getOutput())->toContain('hop-2.1: 2.3.0')
        // La cadena son TODAS las intermedias, en orden, hasta el destino.
        ->and($resultado->getOutput())->toContain('chain: 2.1.0 2.2.0 2.3.0')
        // Un salto de mayor no es automatico: sin una version posterior de la
        // serie del origen a la que ir primero, se remite a la ventana anunciada.
        ->and($resultado->getOutput())->toContain('major: major-last')
        ->and($resultado->getOutput())->toContain('2.2 ok')
        ->and($resultado->getOutput())->toContain('2.1 no');
})->group('RF-PD-10');

it('atribuye cada migracion a la version que la trajo, y la de en desarrollo se queda con el resto', function (): void {
    // Tres versiones: la 2.1 no anadio migraciones (su frontera es la de la
    // 2.0), la 2.2 anadio dos, y la 2.3 en desarrollo se queda con lo demas.
    $matriz = matrizSintetica(<<<'TXT'
        2.0.0 2026_01_01_000000_a
        2.1.0 -
        2.2.0 2026_02_01_000000_c
        2.3.0 *
        TXT);

    $lista = '2026_01_01_000000_a\n2026_01_15_000000_b\n2026_02_01_000000_c\n2026_03_01_000000_d\n';
    $resultado = bashConElActualizador(sprintf(
        'kq_versions_load %s; for v in 2.0.0 2.1.0 2.2.0 2.3.0; do '
        .'echo "$v: $(printf "%s" | kq_migrations_for_version "$v" | tr "\n" " ")"; done',
        escapeshellarg($matriz),
        $lista,
    ));
    unlink($matriz);

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain("2.0.0: 2026_01_01_000000_a \n")
        ->and($resultado->getOutput())->toContain("2.1.0: \n")
        ->and($resultado->getOutput())->toContain("2.2.0: 2026_01_15_000000_b 2026_02_01_000000_c \n")
        ->and($resultado->getOutput())->toContain("2.3.0: 2026_03_01_000000_d \n");
})->group('RF-PD-10');

it('rechaza una matriz de versiones que no cumple su contrato', function (string $contenido, string $motivo): void {
    // El actualizador no adivina: una matriz mal escrita en el paquete es un
    // defecto del paquete y se para ANTES de tocar nada.
    $matriz = matrizSintetica($contenido);
    $resultado = bashConElActualizador(sprintf(
        'if kq_versions_load %s; then echo "aceptada"; else echo "rechazada: $KQ_VERSIONS_ERROR"; fi',
        escapeshellarg($matriz),
    ));
    unlink($matriz);

    expect($resultado->getOutput())->toContain('rechazada')
        ->and($resultado->getOutput())->toContain($motivo);
})->with([
    'versiones desordenadas' => ["2.1.0 -\n2.0.0 -\n", 'is not later than'],
    'el asterisco no es la ultima' => ["2.0.0 *\n2.1.0 -\n", "'*' is only allowed on the last line"],
    'frontera que no es una migracion' => ["2.0.0 create_users\n", 'is not a migration name'],
    'fronteras desordenadas' => ["2.0.0 2026_02_01_000000_b\n2.1.0 2026_01_01_000000_a\n", 'is not later than'],
    'version repetida' => ["2.0.0 -\n2.0.0 -\n", 'listed twice'],
    'linea sin frontera' => ["2.0.0\n", 'expected'],
])->group('RF-PD-10');

it('no exige licencia para actualizar', function (): void {
    // Regla dura 15 y RF-PD-05: una licencia caducada no puede dejar a un
    // cliente sin correcciones de seguridad sobre su registro legal. La lista
    // de precondiciones es un dato del script justamente para poder afirmarlo.
    $resultado = bashConElActualizador('printf "%s\n" "${PRECONDITION_CHECKS[@]}"');

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('check_audit_chain')
        ->and($resultado->getOutput())->toContain('check_backup_config')
        ->and($resultado->getOutput())->not->toContain('license');

    // Y la copia previa no tiene bandera para omitirse: ni en la ayuda ni en el
    // analisis de argumentos.
    $script = Repo::contents('infra/scripts/update.sh');

    expect($script)->not->toMatch('/^\s*--skip-backup\)/m')
        ->and($script)->not->toMatch('/^\s*--force\)/m');

    // La instalacion actual se localiza por las etiquetas de los contenedores.
    // En `docker ps --format`, `.Labels` es una CADENA y `index .Labels` falla
    // en tiempo de ejecucion ("cannot index slice/array with type string"): la
    // etapa 8b lo encontro con la instalacion en marcha y un `2>/dev/null`
    // convirtiendolo en "no hay instalacion". Se lee con `.Label "clave"`.
    // Y solo por el contenedor `app`: los servicios cuya configuracion no
    // cambia entre versiones (redis, imagen fija) no se recrean y conservan el
    // directorio que los creo; tras actualizar habria dos y ninguno seria un error.
    expect($script)->not->toContain('index .Labels')
        ->and($script)->toContain('{{.Label "com.docker.compose.project.config_files"}}')
        ->and($script)->toContain('label=com.docker.compose.service=app');
})->group('RF-PD-05', 'RF-PD-10');

it('sale con 2 sin escribir nada en el paquete cuando falla una precondicion', function (): void {
    // En la maquina de pruebas no hay Docker ni root: el paso 1 falla, y lo que
    // se afirma es que el paquete nuevo sigue SIN .env, sin copia previa del
    // .env y sin candado. El «nada tocado» del codigo 2 es esto.
    $paquete = paqueteDeActualizacion();

    $resultado = ejecutarActualizador(['--check-only', '--compose-file', $paquete['compose']]);

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getOutput())->toContain('Que hacer')
        ->and($resultado->getOutput())->toContain('NO cumplidas')
        ->and(file_exists($paquete['dir'].'/.env'))->toBeFalse()
        ->and(file_exists($paquete['dir'].'/.env.kronoqr-pre-update'))->toBeFalse();

    borrarPaqueteDeActualizacion($paquete['dir']);
})->group('RF-PD-10', 'RQ-11');

it('no deja ni una cadena en espanol cuando se le pide ingles', function (): void {
    $paquete = paqueteDeActualizacion();

    $resultado = ejecutarActualizador(['--lang', 'en', '--check-only', '--compose-file', $paquete['compose']]);
    $salida = $resultado->getOutput().$resultado->getErrorOutput();

    foreach (['Que hacer', 'Precondiciones', 'no encontrado', 'Fichero del paquete', 'Salida ', 'ejecuta'] as $palabra) {
        expect(mb_stripos($salida, $palabra))->toBeFalse(
            'La salida en ingles contiene la palabra espanola "'.$palabra.'".'
        );
    }

    expect($salida)->toContain('What to do');

    borrarPaqueteDeActualizacion($paquete['dir']);
})->group('RF-PD-10');

it('mantiene el catalogo de mensajes del actualizador completo en los dos idiomas', function (): void {
    $resultado = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            '. '.escapeshellarg(Repo::file('infra/scripts/lib/messages.sh')).'; '
            .'. '.escapeshellarg(Repo::file('infra/scripts/lib/messages-update.sh')).'; '
            .'kq_msg_init ""; kq_msg_check_catalog'
        ),
        timeout: 30.0,
    );
    $resultado->run();

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput());
})->group('RF-PD-10');
