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
        // omitir la copia. El 6 casi nunca aparece (toda verificacion fallida
        // deshace); la unica excepcion es un asiento de auditoria que no se
        // pudo escribir tras una actualizacion que SI termino (tarea 5.7, cierre).
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

it('escribe la ventana de mantenimiento para Prometheus de forma atomica', function (): void {
    // Tarea 3.2, decision 6(c). `VentanaDeMantenimientoActiva`
    // (infra/observability/prometheus/rules/maintenance.yml) lee este fichero
    // por el colector textfile de node-exporter: sin el, una actualizacion en
    // marcha se ve identica a un quiosco, una API o un certificado TLS de
    // verdad caidos, y las cuatro alertas suenan a la vez por cada
    // reinicio de servicios que hace el propio actualizador.
    $directorio = sys_get_temp_dir().'/kronoqr-maintenance-'.bin2hex(random_bytes(6));
    mkdir($directorio.'/metrics', 0o755, true);

    $resultado = bashConElActualizador(sprintf(
        'CFG_BACKUP_PATH=%s; write_maintenance_metric 1 1700000000; cat %s',
        escapeshellarg($directorio),
        escapeshellarg($directorio.'/metrics/kronoqr_maintenance.prom'),
    ));

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('kronoqr_maintenance_active 1')
        ->and($resultado->getOutput())->toContain('kronoqr_maintenance_since_timestamp_seconds 1700000000')
        // TYPE gauge, no counter: el valor puede bajar de 1 a 0 (RN-15 no
        // aplica aqui, pero la semantica de Prometheus si).
        ->and($resultado->getOutput())->toContain('# TYPE kronoqr_maintenance_active gauge');

    // Vuelve a poner el fichero a 0: es lo que hace `lift_maintenance`, el
    // camino feliz y la vuelta atras. El fichero se SOBRESCRIBE entero (no se
    // incrementa ni se anexa), como cualquier gauge de este directorio.
    $segundoResultado = bashConElActualizador(sprintf(
        'CFG_BACKUP_PATH=%s; write_maintenance_metric 0 1700000000; cat %s',
        escapeshellarg($directorio),
        escapeshellarg($directorio.'/metrics/kronoqr_maintenance.prom'),
    ));

    expect($segundoResultado->getExitCode())->toBe(0, $segundoResultado->getErrorOutput())
        ->and($segundoResultado->getOutput())->toContain('kronoqr_maintenance_active 0')
        ->and($segundoResultado->getOutput())->not->toContain('kronoqr_maintenance_active 1');

    // Permisos legibles por CUALQUIER uid (0644): update.sh corre en el
    // anfitrion, normalmente como root, y node-exporter siempre lee como el
    // uid 1000 del contenedor `app`. Sin el bit de "otros", el fichero
    // existiria y node-exporter no podria leerlo, que es peor que si no
    // existiera: la alerta de disponibilidad de node-exporter no lo veria.
    $permisos = fileperms($directorio.'/metrics/kronoqr_maintenance.prom') & 0o777;
    expect(sprintf('%o', $permisos))->toBe('644');

    unlink($directorio.'/metrics/kronoqr_maintenance.prom');
    rmdir($directorio.'/metrics');
    rmdir($directorio);
})->group('RF-PD-10');

it('no falla si el directorio de metricas no existe: no hay a quien avisar', function (): void {
    // Perfil `observability` apagado, o una instalacion que todavia no ha
    // hecho su primera copia (que es quien crea el arbol de BACKUP_PATH). En
    // ninguno de los dos casos update.sh puede fallar por esto: escribir la
    // ventana de mantenimiento es instrumentacion, nunca una precondicion.
    $directorio = sys_get_temp_dir().'/kronoqr-maintenance-ausente-'.bin2hex(random_bytes(6));

    $resultado = bashConElActualizador(sprintf(
        'CFG_BACKUP_PATH=%s; write_maintenance_metric 1 1700000000; echo "sin-error"',
        escapeshellarg($directorio),
    ));

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('sin-error')
        ->and(is_dir($directorio))->toBeFalse('write_maintenance_metric no debe crear el directorio: solo escribe si ya existe.');
})->group('RF-PD-10');

/*
 * ------------------------------------------------------------------------
 * `kq_app_knows_command` — «la version que esta en pie, ¿conoce este comando?»
 *
 * Es la pregunta de la que depende que la vuelta atras deje o no el asiento
 * `system.restored_from_backup` (regla dura 6, RF-PD-10, RS-07): el unico
 * rastro, dentro del propio registro legal, de que un intervalo de fichajes
 * reales quedo fuera de la base que sirve ahora.
 *
 * Hasta el 22-09-2026 se preguntaba con `artisan list --raw | grep -q`, y esa
 * tuberia responde NO cuando la respuesta es SI: `grep -q` cierra el tubo al
 * encontrar la linea, el cliente de Docker recibe EPIPE mientras vuelca el
 * resto del catalogo y termina con 1, y `pipefail` hace valer ese 1. La etapa
 * 8b tenia la misma tuberia copiada en ci.yml, asi que el paso pasaba o
 * fallaba segun a cual de las dos copias le tocara equivocarse (ejecuciones
 * 35697335929 en verde y 35700735466 en rojo, con el MISMO codigo de la
 * version anterior).
 * ------------------------------------------------------------------------
 */

it('dice que SI conoce el comando aunque el catalogo no quepa en un tubo', function (): void {
    // El relleno pasa de 140 KiB: mas que el buffer de una tuberia de Linux
    // (64 KiB), asi que con la forma antigua el productor SIEMPRE muere de
    // SIGPIPE. Con el catalogo capturado en una variable, nadie cierra nada.
    $fragmento = <<<'BASH'
        falso() {
          printf "about  Muestra informacion\n"
          printf "compliance:record-system-event  Deja en audit_log\n"
          printf "relleno:%05d pad\n" {1..8000}
        }
        estado=0; kq_app_knows_command falso compliance:record-system-event || estado=$?
        echo "FUNCION=${estado}"
        if falso exec -T app php artisan list --raw 2>/dev/null | grep -q "^compliance:record-system-event"; then
          echo "TUBERIA=SI"
        else
          echo "TUBERIA=NO"
        fi
        BASH;

    $resultado = bashConElActualizador($fragmento);

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('FUNCION=0')
        // Control negativo: la forma que tenia update.sh sobre EL MISMO
        // catalogo responde NO. Si algun dia esta linea pasara a decir SI,
        // la prueba de arriba habria dejado de demostrar nada.
        ->and($resultado->getOutput())->toContain('TUBERIA=NO');
})->group('RF-PD-10', 'RS-07');

it('dice que NO lo conoce solo cuando artisan responde y el nombre no esta', function (): void {
    // Comparacion por campo exacto, no por prefijo: `...eventual` no es
    // `...event`. Con una expresion regular sobre un nombre con `:` y `-`
    // esto se decide por accidente.
    $fragmento = <<<'BASH'
        sin_comando() { printf "about  x\ncompliance:record-system-eventual  parecido\n"; }
        estado=0; kq_app_knows_command sin_comando compliance:record-system-event || estado=$?
        echo "NO_LO_CONOCE=${estado}"
        BASH;

    $resultado = bashConElActualizador($fragmento);

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('NO_LO_CONOCE=1');
})->group('RF-PD-10', 'RS-07');

it('distingue «no lo conoce» de «no he podido preguntar» y conserva el error', function (): void {
    // El `2>/dev/null` de la version anterior convertia cualquier fallo real
    // —contenedor que aun no acepta `exec`, demonio caido— en un «no lo
    // conoce» indistinguible, y con el un asiento legal que nadie sabe que
    // falta. Aqui sale 2, con su mensaje propio y el error a la vista.
    $fragmento = <<<'BASH'
        roto() { printf "Error response from daemon: container is not running\n" >&2; return 1; }
        KQ_APP_COMMAND_ATTEMPTS=1
        estado=0; kq_app_knows_command roto compliance:record-system-event || estado=$?
        echo "NO_SE_PUDO=${estado}"
        echo "ERROR=${KQ_APP_COMMAND_ERROR}"
        BASH;

    $resultado = bashConElActualizador($fragmento);

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and($resultado->getOutput())->toContain('NO_SE_PUDO=2')
        ->and($resultado->getOutput())->toContain('ERROR=Error response from daemon');

    // Y el actualizador tiene un mensaje distinto para ese desenlace, en los
    // dos idiomas: «no se ha podido preguntar» no se le cuenta al IT del
    // hotel como «tu version no lo conoce».
    $mensajes = (string) file_get_contents(Repo::file('infra/scripts/lib/messages-update.sh'));
    expect($mensajes)->toContain('KQ_MSG_ES[u_rollback_audit_entry_unknown]=')
        ->and($mensajes)->toContain('KQ_MSG_EN[u_rollback_audit_entry_unknown]=');
})->group('RF-PD-10', 'RS-07');

it('pregunta lo mismo y con la misma funcion en update.sh y en la etapa 8b', function (): void {
    // Dos copias de la misma pregunta es como se llego al fallo: no basta con
    // arreglar una. Ni el script ni el workflow pueden volver a preguntarlo
    // con una tuberia, ni tapar el error con 2>/dev/null.
    $actualizador = (string) file_get_contents(Repo::file('infra/scripts/update.sh'));
    $workflow = (string) file_get_contents(Repo::file('.github/workflows/ci.yml'));

    expect($actualizador)->toContain('lib/app-commands.sh')
        ->and($actualizador)->toContain('kq_app_knows_command compose_rollback compliance:record-system-event')
        ->and($workflow)->toContain('infra/scripts/lib/app-commands.sh')
        ->and($workflow)->toContain('kq_app_knows_command compose compliance:record-system-event');

    foreach (['update.sh' => $actualizador, 'ci.yml' => $workflow] as $nombre => $contenido) {
        expect(preg_match('/artisan list --raw[^\n]*\|[^\n]*grep/', $contenido))->toBe(
            0,
            $nombre.' vuelve a preguntar por un comando con `artisan list --raw | grep`: esa tuberia '
            .'responde NO cuando la respuesta es SI. Usa kq_app_knows_command (infra/scripts/lib/app-commands.sh).'
        );
    }

    // La biblioteca compartida viaja en el paquete de entrega (package.sh
    // copia lib/ entero), o el actualizador del hotel no arrancaria.
    expect((string) file_get_contents(Repo::file('infra/scripts/package.sh')))
        ->toContain('infra/scripts/lib');
})->group('RF-PD-10', 'RS-07');

it('migra y restaura por los servicios puntuales, y crea el rol de copias antes de arrancar el planificador (AUD-1)', function (): void {
    // AUD-1, regla dura 6. La contrasena del migrador (superusuario) solo puede
    // existir en los contenedores efimeros `migrate` y `restore`, asi que ni las
    // migraciones ni la vuelta atras pueden volver a ir por `app`. Se comprueba
    // sobre el texto porque ejercitarlo exige Docker; ⑧b lo cubre de verdad.
    $actualizador = (string) file_get_contents(Repo::file('infra/scripts/update.sh'));
    $mensajes = (string) file_get_contents(Repo::file('infra/scripts/lib/messages-update.sh'));

    expect($actualizador)
        ->toContain('run --rm --no-deps -T migrate php artisan migrate --force --database=pgsql_migrator')
        ->toContain('run --rm --no-deps -T restore bash "${KQ_CONTAINER_SCRIPTS}/restore.sh"')
        ->toContain('run --rm --no-deps -T scheduler php artisan backup:run --mode=dump');
    expect($actualizador)->not->toContain('-T app php artisan migrate');
    expect($actualizador)->not->toContain('-T app bash "${KQ_CONTAINER_SCRIPTS}/restore.sh"');
    expect($actualizador)->not->toContain('exec -T app php artisan backup:run');

    // La ayuda de la vuelta atras incompleta tambien: una persona la copia tal cual.
    expect($mensajes)->toContain('run --rm --no-deps restore');
    expect($mensajes)->not->toContain('run --rm --no-deps app');

    // El rol se crea al final del paso 4, antes de que el paso 5 levante el
    // `scheduler` nuevo (el unico runtime que recibe BACKUP_DB_*), y la
    // contrasena no pasa por argv: entra por la entrada estandar.
    $creacionDelRol = strpos($actualizador, "\n  provision_backup_role\n}");
    $arranqueDelPlanificador = strpos($actualizador, "\nphase_start_and_verify() {");

    expect($creacionDelRol)->not->toBeFalse('update.sh ya no termina el paso 4 con provision_backup_role (AUD-1).');
    expect($arranqueDelPlanificador)->not->toBeFalse('update.sh ya no define phase_start_and_verify.');
    expect((int) $creacionDelRol)->toBeLessThan((int) $arranqueDelPlanificador);
    expect($actualizador)->toContain('03-backup-role.sh --password-stdin')
        ->and($actualizador)->toContain('kq_env_set "${ENV_FILE}" "BACKUP_DB_PASSWORD" "${password}"')
        ->and($actualizador)->not->toMatch('/DB_BACKUP_PASSWORD=/');
})->group('RS-07', 'RF-PD-10');

it('en modo in-place la copia del .env de la vuelta atras anula los digests y el .env vivo no se toca (A6-2, ADR-053)', function (): void {
    // El docker-compose.yml nuevo lleva el digest de la version nueva como valor
    // por defecto y la vuelta atras in-place lo reutiliza: sin las tres variables
    // VACIAS (definidas, no ausentes) en ROLLBACK_ENV, Docker mandaria por el
    // digest y levantaria la version nueva sobre la copia restaurada. ⑧b (P2)
    // lo ejercita de verdad con un registro local.
    $proceso = bashConElActualizador(<<<'BASH'
        d="$(mktemp -d)"
        trap 'rm -rf "${d}"' EXIT
        printf 'APP_KEY=base64:prueba\nIMAGE_TAG=2.1.0\nIMAGE_DIGEST_PHP=@sha256:viejo\n' >"${d}/.env"
        IN_PLACE=1
        ENV_FILE="${d}/.env"
        COMPOSE_FILE="${d}/docker-compose.yml"
        prepare_package
        printf 'ROLLBACK_ENV=%s\n' "${ROLLBACK_ENV##*/}"
        printf '%s\n' '--- rollback'
        cat "${ROLLBACK_ENV}"
        printf '%s\n' '--- vivo'
        cat "${ENV_FILE}"
        BASH);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput().$proceso->getOutput());

    [$cabecera, $resto] = explode("--- rollback\n", $proceso->getOutput(), 2);
    [$rollback, $vivo] = explode("--- vivo\n", $resto, 2);

    expect($cabecera)->toContain('ROLLBACK_ENV=.env.kronoqr-pre-update')
        ->and($rollback)->toContain("IMAGE_TAG=2.1.0\n")
        ->and($rollback)->toMatch('/^IMAGE_DIGEST_PHP=$/m')
        ->and($rollback)->toMatch('/^IMAGE_DIGEST_NGINX=$/m')
        ->and($rollback)->toMatch('/^IMAGE_DIGEST_POSTGRES=$/m')
        ->and($rollback)->not->toContain('sha256:viejo')
        ->and($vivo)->toContain('IMAGE_DIGEST_PHP=@sha256:viejo')
        ->and($vivo)->not->toContain('IMAGE_DIGEST_NGINX');
})->group('RF-PD-10', 'RS-08');

it('tras una vuelta atras in place el reintento retira las vacias que puso update.sh, y solo esas (A6-2)', function (): void {
    // La vuelta atras completada deja como .env la copia con IMAGE_DIGEST_*
    // vacias y una marca. El reintento las retira (corre las imagenes fijadas)
    // y el aviso de digests anulados no salta por ellas. Unas vacias SIN marca
    // son el opt-out de un servidor sin internet: no se tocan y si avisan.
    $proceso = bashConElActualizador(<<<'BASH'
        CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0
        d="$(mktemp -d)"
        trap 'rm -rf "${d}"' EXIT
        printf 'APP_KEY=base64:prueba\nIMAGE_TAG=2.1.0\n' >"${d}/.env"
        kq_env_mark_rollback_digests "${d}/.env"
        kq_env_mark_rollback_digests "${d}/.env"
        printf 'marcas=%s\n' "$(grep -c '^# KQ_ROLLBACK_DIGESTS' "${d}/.env")"
        check_image_digest_overrides "${d}/.env" "${d}" >/dev/null
        printf 'aviso-con-marca=[%s]\n' "${KQ_DIGEST_OVERRIDES}"
        IN_PLACE=1
        ENV_FILE="${d}/.env"
        COMPOSE_FILE="${d}/docker-compose.yml"
        prepare_package
        printf '%s\n' '--- vivo'
        cat "${ENV_FILE}"
        printf '%s\n' '--- rollback'
        cat "${ROLLBACK_ENV}"
        printf '%s\n' '--- operador'
        printf 'php sha256:%064d\n' 0 >"${d}/images.lock"
        printf 'APP_KEY=x\nIMAGE_DIGEST_PHP=\n' >"${d}/.env"
        kq_env_drop_rollback_digests "${d}/.env"
        cat "${d}/.env"
        check_image_digest_overrides "${d}/.env" "${d}" >/dev/null
        printf 'aviso-sin-marca=[%s]\n' "${KQ_DIGEST_OVERRIDES}"
        BASH);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput().$proceso->getOutput());

    [$cabecera, $resto] = explode("--- vivo\n", $proceso->getOutput(), 2);
    [$vivo, $resto] = explode("--- rollback\n", $resto, 2);
    [$rollback, $operador] = explode("--- operador\n", $resto, 2);

    expect($cabecera)->toContain('marcas=1')
        ->and($cabecera)->toContain('aviso-con-marca=[]')
        ->and($vivo)->not->toContain('IMAGE_DIGEST_')
        ->and($vivo)->not->toContain('KQ_ROLLBACK_DIGESTS')
        ->and($vivo)->toContain("IMAGE_TAG=2.1.0\n")
        ->and($rollback)->toMatch('/^IMAGE_DIGEST_PHP=$/m')
        ->and($rollback)->toContain('# KQ_ROLLBACK_DIGESTS')
        ->and($operador)->toMatch('/^IMAGE_DIGEST_PHP=$/m')
        ->and($operador)->toContain('aviso-sin-marca=[IMAGE_DIGEST_PHP]');
})->group('RF-PD-10', 'RS-08');

it('avisa cuando el .env declara IMAGE_DIGEST_* distintas de las del paquete y calla cuando coinciden o no estan', function (): void {
    $lock = 'php sha256:'.str_repeat('a', 64)."\nnginx sha256:".str_repeat('b', 64)."\npostgres sha256:".str_repeat('c', 64)."\n";

    $proceso = bashConElActualizador(<<<BASH
        CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0
        d="\$(mktemp -d)"
        trap 'rm -rf "\${d}"' EXIT
        printf '%s' '{$lock}' >"\${d}/images.lock"

        printf 'APP_KEY=x\n' >"\${d}/.env"
        check_image_digest_overrides "\${d}/.env" "\${d}" >/dev/null
        printf 'sin-declarar=[%s]\n' "\${KQ_DIGEST_OVERRIDES}"

        printf 'IMAGE_DIGEST_PHP=@sha256:%s\n' "$(printf 'a%.0s' $(seq 1 64))" >"\${d}/.env"
        check_image_digest_overrides "\${d}/.env" "\${d}" >/dev/null
        printf 'igual-al-paquete=[%s]\n' "\${KQ_DIGEST_OVERRIDES}"

        printf 'IMAGE_DIGEST_PHP=\nIMAGE_DIGEST_POSTGRES=@sha256:otro\n' >"\${d}/.env"
        salida="\$(check_image_digest_overrides "\${d}/.env" "\${d}")"
        check_image_digest_overrides "\${d}/.env" "\${d}" >/dev/null
        printf 'anuladas=[%s]\n' "\${KQ_DIGEST_OVERRIDES}"
        printf 'salida=%s\n' "\${salida}"
        BASH);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput().$proceso->getOutput());

    expect($proceso->getOutput())
        ->toContain("sin-declarar=[]\n")
        ->toContain("igual-al-paquete=[]\n")
        ->toContain("anuladas=[IMAGE_DIGEST_PHP, IMAGE_DIGEST_POSTGRES]\n")
        ->toContain('IMAGE_DIGEST_PHP, IMAGE_DIGEST_POSTGRES');
})->group('RF-PD-10', 'RS-08');
