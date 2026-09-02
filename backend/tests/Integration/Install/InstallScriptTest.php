<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El instalador, EJECUTADO de verdad (RF-PD-02, RQ-11).
 *
 * ## Por que existe este fichero
 *
 * `RF-PD-02` y `RQ-11` figuraban cubiertos por cinco pruebas de
 * `QualityGatesTest` que son **greps sobre ficheros**: comprueban que el script
 * existe y que dice ciertas cosas, no que haga ninguna. Un `install.sh` con un
 * error de sintaxis en la fase 1 las habria pasado todas.
 *
 * Lo que se ejecuta aqui son los caminos que NO necesitan Docker ni root, que
 * son justamente los que deciden si el servidor de un hotel queda tocado o no:
 * la comprobacion de requisitos, los codigos de salida y el tratamiento del
 * `.env`. La instalacion completa —fases 4 y 5— la prueba la etapa ⑧ de la CI,
 * porque exige una maquina Linux con Docker y las imagenes de entrega.
 *
 * ## Por que se carga el script en vez de solo invocarlo
 *
 * `install.sh` termina con una guarda `[ "${BASH_SOURCE[0]}" = "$0" ]`, asi que
 * se puede hacer `source` de el sin instalar nada. Es lo que permite ejercitar
 * `kq_env_value` y `set_env_value` —las dos piezas que tocan el fichero de
 * configuracion del cliente— de forma aislada y determinista.
 */

/**
 * Ejecuta install.sh con argumentos y entorno controlados.
 *
 * @param  list<string>  $argumentos
 * @param  array<string, string>  $env
 */
function ejecutarInstalador(array $argumentos, array $env = []): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/scripts/install.sh'), ...$argumentos],
        env: array_merge(['KRONOQR_LANG' => 'es'], $env),
        timeout: 120.0,
    );
    $process->run();

    return $process;
}

/**
 * Carga install.sh sin ejecutarlo y corre un fragmento con sus funciones.
 */
function bashConElInstalador(string $script): Process
{
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/install.sh')).'; '.$script
        ),
        timeout: 60.0,
    );
    $process->run();

    return $process;
}

/**
 * Arma un paquete de entrega minimo en un directorio temporal.
 *
 * @return array{dir: string, env: string, compose: string}
 */
function paqueteDePrueba(): array
{
    $dir = sys_get_temp_dir().'/kronoqr-install-'.bin2hex(random_bytes(6));

    mkdir($dir.'/certs', 0o755, true);
    copy(Repo::file('infra/compose.prod.yaml'), $dir.'/docker-compose.yml');
    copy(Repo::file('.env.example'), $dir.'/.env.example');
    copy(Repo::file('.env.example'), $dir.'/.env');
    copy(Repo::file('VERSION'), $dir.'/VERSION');
    touch($dir.'/certs/tls.crt');
    touch($dir.'/certs/tls.key');
    // Legibles por «otros» a proposito. La fase 1 comprueba que el uid 101 —el
    // del borde, que corre sin privilegios— podra leerlos, y dentro del
    // contenedor de pruebas se corre como uid 1000 y no se puede hacer `chown`
    // a 101. El unico estado alcanzable aqui que el borde SI podria leer es el
    // de lectura universal, que el instalador acepta avisando.
    chmod($dir.'/certs/tls.crt', 0o444);
    chmod($dir.'/certs/tls.key', 0o444);

    // Lo que rellenaria el IT del hotel. Los cuatro valores de red se cambian a
    // proposito: la fase 1 los compara con la plantilla y rechaza los de ejemplo.
    $env = (string) file_get_contents($dir.'/.env');
    $env = preg_replace([
        '/^APP_ENV=.*$/m',
        '/^APP_URL=.*$/m',
        '/^KIOSK_VLAN_CIDR=.*$/m',
        '/^PORTAL_INTERNAL_CIDR=.*$/m',
        '/^METRICS_ALLOW_CIDR=.*$/m',
        '/^TLS_ALLOW_SELF_SIGNED=.*$/m',
        '/^BACKUP_PATH=.*$/m',
    ], [
        'APP_ENV=production',
        'APP_URL=https://fichaje.prueba.local',
        'KIOSK_VLAN_CIDR=10.92.0.0/24',
        'PORTAL_INTERNAL_CIDR=10.90.0.0/24',
        'METRICS_ALLOW_CIDR=10.91.0.5/32',
        'TLS_ALLOW_SELF_SIGNED=false',
        'BACKUP_PATH='.$dir.'/copias',
    ], (string) $env);

    file_put_contents($dir.'/.env', (string) $env);
    mkdir($dir.'/copias', 0o755, true);

    return ['dir' => $dir, 'env' => $dir.'/.env', 'compose' => $dir.'/docker-compose.yml'];
}

function borrarPaquete(string $dir): void
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

it('sale con 1 y no toca nada ante una opcion que no existe', function (): void {
    // Codigo 1 de la tabla comun. Un argumento mal escrito no puede parecerse a
    // un fallo de la instalacion.
    $resultado = ejecutarInstalador(['--esta-opcion-no-existe']);

    expect($resultado->getExitCode())->toBe(1)
        ->and($resultado->getErrorOutput())->toContain('--help');
})->group('RF-PD-02', 'RQ-11');

it('sale con 2 y deja el .env BYTE A BYTE intacto cuando falta un requisito', function (): void {
    // El nucleo del §3.5: los requisitos se comprueban ANTES de tocar nada. La
    // comprobacion no es «parece que no ha escrito», es el hash del fichero.
    $paquete = paqueteDePrueba();
    unlink($paquete['dir'].'/certs/tls.crt');

    $antes = (string) md5_file($paquete['env']);

    $resultado = ejecutarInstalador(['--compose-file', $paquete['compose']]);

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getOutput())->toContain('Falta el certificado TLS')
        // El mensaje dice QUE HACER, no solo que falla.
        ->and($resultado->getOutput())->toContain('Que hacer')
        ->and(md5_file($paquete['env']))->toBe($antes)
        // Y ninguna copia previa a medias.
        ->and(file_exists($paquete['env'].'.kronoqr-pre-install'))->toBeFalse();

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02', 'RQ-11');

it('rechaza los valores de ejemplo de la plantilla, que no son de ninguna instalacion', function (): void {
    // Con APP_URL=https://localhost el sistema arranca, toda comprobacion pasa
    // -la verificacion final sondea 127.0.0.1- y NINGUN quiosco puede llegar.
    // Nada posterior lo detecta, asi que tiene que detectarlo la fase 1.
    $paquete = paqueteDePrueba();

    $env = (string) file_get_contents($paquete['env']);
    $env = (string) preg_replace('/^APP_URL=.*$/m', 'APP_URL=https://localhost', $env);
    file_put_contents($paquete['env'], $env);

    $resultado = ejecutarInstalador(['--compose-file', $paquete['compose']]);

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getOutput())->toContain('APP_URL sigue con el valor de ejemplo');

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02');

it('se niega a instalar en produccion con un certificado autofirmado', function (): void {
    // Con true, el borde se genera uno: las tablets avisarian cada manana y
    // alguien acabaria desactivando la comprobacion. Es el valor por defecto de
    // la plantilla, asi que quedarselo es el error facil.
    $paquete = paqueteDePrueba();

    $env = (string) file_get_contents($paquete['env']);
    $env = (string) preg_replace('/^TLS_ALLOW_SELF_SIGNED=.*$/m', 'TLS_ALLOW_SELF_SIGNED=true', $env);
    file_put_contents($paquete['env'], $env);

    $resultado = ejecutarInstalador(['--compose-file', $paquete['compose']]);

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getOutput())->toContain('TLS_ALLOW_SELF_SIGNED=true');

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02', 'RS-09');

it('no escribe nada con --check-only, y lo dice', function (): void {
    // El modo con el que el IT del hotel reserva la ventana de mantenimiento.
    $paquete = paqueteDePrueba();
    $antes = (string) md5_file($paquete['env']);

    $resultado = ejecutarInstalador(['--check-only', '--compose-file', $paquete['compose']]);

    // Sale 0 si el entorno cumple; si la maquina de pruebas no tiene Docker o
    // no es root, sale 2. Las dos son respuestas correctas de la fase 1: lo que
    // esta prueba fija es que NO ESCRIBE, pase lo que pase.
    expect($resultado->getExitCode())->toBeIn([0, 2])
        ->and(md5_file($paquete['env']))->toBe($antes)
        ->and(file_exists($paquete['env'].'.kronoqr-pre-install'))->toBeFalse();

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02', 'RQ-11');

it('no deja ni una cadena en espanol cuando se le pide ingles', function (): void {
    // La Definicion de Terminado exige los dos idiomas, y el fallo tipico no es
    // que falte la traduccion: es que quede una cadena suelta fuera del
    // catalogo y el IT del hotel lea una ayuda a medio traducir.
    $paquete = paqueteDePrueba();
    unlink($paquete['dir'].'/certs/tls.crt');

    $resultado = ejecutarInstalador(['--lang', 'en', '--compose-file', $paquete['compose']]);
    $salida = $resultado->getOutput().$resultado->getErrorOutput();

    // Palabras que solo pueden venir de una cadena espanola sin traducir.
    foreach ([
        'Que hacer', 'comprueba', 'instala ', 'servidor', 'fichero',
        'plantilla', 'certificado', 'Salida ', 'requisitos', 'ejecuta',
    ] as $palabra) {
        expect(mb_stripos($salida, $palabra))->toBeFalse(
            'La salida en ingles contiene la palabra espanola "'.$palabra.'".'
        );
    }

    expect($salida)->toContain('What to do');

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02');

it('mantiene el catalogo de mensajes completo en los dos idiomas', function (): void {
    // `kq_msg_check_catalog` existia y no la llamaba nadie, aunque su comentario
    // decia que si. Una comprobacion que nadie ejecuta no comprueba nada.
    $resultado = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            '. '.escapeshellarg(Repo::file('infra/scripts/lib/messages.sh')).'; kq_msg_init ""; kq_msg_check_catalog'
        ),
        timeout: 30.0,
    );
    $resultado->run();

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput());
})->group('RF-PD-02');

it('lee el .env igual que Docker Compose, y no de otra manera', function (): void {
    // Los dos casos que separaban a los dos lectores que habia antes de la
    // tarea 5.4. La regla es la de Compose: `#` solo abre comentario si va
    // PRECEDIDO DE ESPACIO. Si esto divergiera, el instalador crearia el arbol
    // de copias en un sitio y la copia nocturna lo buscaria en otro.
    $fichero = sys_get_temp_dir().'/kronoqr-env-'.bin2hex(random_bytes(6));

    file_put_contents($fichero, implode("\n", [
        'CON_COMILLAS="/srv/copias"   ',
        'CON_ALMOHADILLA_PEGADA=/srv/copias#1',
        'CON_COMENTARIO=/srv/copias # el destino',
        'CON_ESPACIOS=   /srv/copias   ',
        '# UN_COMENTARIO=no',
        'export CON_EXPORT=valor',
    ])."\n");

    $lee = function (string $clave) use ($fichero): string {
        $proceso = bashConElInstalador('env_value '.escapeshellarg($fichero).' '.escapeshellarg($clave));

        return $proceso->getOutput();
    };

    expect($lee('CON_COMILLAS'))->toBe('/srv/copias')
        ->and($lee('CON_ALMOHADILLA_PEGADA'))->toBe('/srv/copias#1')
        ->and($lee('CON_COMENTARIO'))->toBe('/srv/copias')
        ->and($lee('CON_ESPACIOS'))->toBe('/srv/copias')
        ->and($lee('CON_EXPORT'))->toBe('valor')
        ->and($lee('UN_COMENTARIO'))->toBe('')
        ->and($lee('NO_ESTA'))->toBe('');

    unlink($fichero);
})->group('RF-PD-02');

it('escribe un secreto sin perder ni un comentario del .env del cliente', function (): void {
    // El .env que toca el instalador lo ha escrito el IT del hotel y es la unica
    // documentacion de esa instalacion. Sustituir una clave no puede costarle
    // sus comentarios ni duplicarle una linea.
    $fichero = sys_get_temp_dir().'/kronoqr-env-'.bin2hex(random_bytes(6));
    copy(Repo::file('.env.example'), $fichero);

    $comentariosAntes = (int) preg_match_all('/^#/m', (string) file_get_contents($fichero));

    bashConElInstalador(
        'ENV_FILE='.escapeshellarg($fichero).'; '
        .'set_env_value "$ENV_FILE" APP_KEY "base64:valor-de-prueba"; '
        .'set_env_value "$ENV_FILE" CLAVE_NUEVA "otro"'
    );

    $despues = (string) file_get_contents($fichero);

    expect((int) preg_match_all('/^#/m', $despues))->toBe($comentariosAntes)
        ->and($despues)->toContain('APP_KEY=base64:valor-de-prueba')
        ->and($despues)->toContain('CLAVE_NUEVA=otro')
        // Sustituida, no duplicada.
        ->and(preg_match_all('/^APP_KEY=/m', $despues))->toBe(1)
        // Y lo intocable sigue donde estaba.
        ->and($despues)->toMatch('/^APP_TIMEZONE=UTC$/m');

    unlink($fichero);
})->group('RF-PD-02');

it('deshace con rm -rf y no con rmdir, porque el archivo de WAL nunca esta vacio', function (): void {
    // `${BACKUP_PATH}/wal` es un bind mount y PostgreSQL archiva desde el primer
    // segundo. Un `rmdir` sobre un directorio con segmentos dentro falla, y eso
    // convertia TODO fallo de la fase 4 en un codigo 5 -«intervencion manual»-
    // en vez de un 4, contradiciendo al mensaje del propio instalador.
    //
    // Se comprueba sobre el texto porque el camino que lo ejecuta necesita
    // Docker; la etapa ⑧ lo ejercita de verdad en su escenario E.
    $script = (string) file_get_contents(Repo::file('infra/scripts/install.sh'));

    expect($script)->not->toMatch("/register_undo[^\n]*\n?[^\n]*rmdir/")
        ->and($script)->toContain("rm -rf '\${wal}'");
})->group('RF-PD-02');

it('se niega a instalar si el borde no podra leer la clave privada del certificado', function (): void {
    // EL FALLO DE LA SEGUNDA EJECUCION DE LA ETAPA ⑧, reproducido sin Docker.
    //
    // El borde HTTP corre SIN PRIVILEGIOS dentro de su contenedor, con el uid
    // 101. Un IT que copie su `tls.key` como root la deja root:root 0600
    // —`openssl` escribe las claves asi y `cp` conserva el modo— y nginx entra
    // en bucle de reinicio con «cannot load certificate key … Permission
    // denied». Sin esta comprobacion, el sintoma no aparecia hasta la sonda de
    // la fase 5, que ademas informaba de que «los servicios estan en pie».
    //
    // Aqui se reproduce con los permisos, que es lo que de verdad decide: dentro
    // del contenedor de pruebas no se puede hacer `chown` a 101, pero una clave
    // sin bit de lectura para «otros» y de un propietario que no es el 101 es
    // exactamente el caso que rompia.
    $paquete = paqueteDePrueba();
    chmod($paquete['dir'].'/certs/tls.key', 0o600);

    $resultado = ejecutarInstalador(['--compose-file', $paquete['compose']]);
    $salida = $resultado->getOutput();

    expect($resultado->getExitCode())->toBe(2)
        ->and($salida)->toContain('NO puede leer tls.key')
        // El mensaje tiene que traer la orden, no solo el diagnostico.
        ->and($salida)->toContain('chown 101:101')
        // Y advertir del caso en el que esa orden seria dañina.
        ->and($salida)->toContain('Let\'s Encrypt')
        // Nada escrito: sigue siendo la fase 1.
        ->and(file_exists($paquete['env'].'.kronoqr-pre-install'))->toBeFalse();

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02', 'RS-09');

it('avisa, sin bloquear, de una clave privada legible por todo el servidor', function (): void {
    // 0444 es legible por el borde, asi que la instalacion FUNCIONA: no puede
    // ser un fallo. Pero una clave privada de TLS que puede copiar cualquiera
    // con una sesion en la maquina es un problema distinto, y callarlo seria
    // peor que avisarlo.
    $paquete = paqueteDePrueba();

    $resultado = ejecutarInstalador(['--check-only', '--compose-file', $paquete['compose']]);
    $salida = $resultado->getOutput();

    expect($salida)->toContain('legible por cualquier usuario del servidor')
        ->and($salida)->toContain('[aviso]');

    borrarPaquete($paquete['dir']);
})->group('RF-PD-02', 'RS-09');

it('responde quien puede leer un fichero mirando propietario y modo, no el proceso actual', function (): void {
    // `[ -r fichero ]` no sirve: el instalador corre como root y a root le dice
    // que si sobre cualquier cosa. La pregunta es si podra leerlo OTRO proceso.
    $dir = sys_get_temp_dir().'/kronoqr-perm-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    $casos = [
        // modo => si el uid 101 puede leerlo, y por que via
        0o600 => ['esperado' => 1, 'via' => ''],
        0o640 => ['esperado' => 1, 'via' => ''],
        0o644 => ['esperado' => 0, 'via' => 'other'],
        0o444 => ['esperado' => 0, 'via' => 'other'],
    ];

    foreach ($casos as $modo => $caso) {
        $fichero = $dir.'/f'.decoct($modo);
        touch($fichero);
        chmod($fichero, $modo);

        $proceso = bashConElInstalador(
            'file_readable_by_uid '.escapeshellarg($fichero).' 101 || exit $?'
        );

        expect($proceso->getExitCode())->toBe(
            $caso['esperado'],
            'Modo 0'.decoct($modo).': se esperaba '.$caso['esperado'].'.'
        );

        if ($caso['via'] !== '') {
            expect($proceso->getOutput())->toBe($caso['via']);
        }

        unlink($fichero);
    }

    rmdir($dir);
})->group('RF-PD-02');

it('espera tambien al borde antes de dar la fase 4 por buena', function (): void {
    // Un nginx en bucle de reinicio no llega nunca a `healthy`. Si la fase 4 no
    // lo esperara, el fallo caeria en la sonda de la fase 5 con el codigo 6
    // —«los servicios estan en pie»— en vez de en la 4, que es la que deshace y
    // se puede reintentar. Se comprueba sobre el texto porque ejercitarlo exige
    // Docker; la etapa ⑧ lo cubre de verdad.
    $script = (string) file_get_contents(Repo::file('infra/scripts/install.sh'));

    expect($script)->toContain('wait_for_healthy nginx');
    // Y que el orden sea el correcto: el borde DESPUES de la aplicacion a la
    // que proxya, y antes de las migraciones.
    $posicion = function (string $aguja) use ($script): int {
        $indice = strpos($script, $aguja);

        if ($indice === false) {
            throw new RuntimeException('install.sh ya no contiene "'.$aguja.'".');
        }

        return $indice;
    };

    expect($posicion('wait_for_healthy app'))->toBeLessThan($posicion('wait_for_healthy nginx'))
        ->and($posicion('wait_for_healthy nginx'))->toBeLessThan($posicion('artisan migrate'));
})->group('RF-PD-02');
