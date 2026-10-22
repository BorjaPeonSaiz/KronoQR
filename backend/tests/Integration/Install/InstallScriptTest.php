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
        '/^IMAGE_REGISTRY=.*$/m',
    ], [
        'APP_ENV=production',
        'APP_URL=https://fichaje.prueba.local',
        'KIOSK_VLAN_CIDR=10.92.0.0/24',
        'PORTAL_INTERNAL_CIDR=10.90.0.0/24',
        'METRICS_ALLOW_CIDR=10.91.0.5/32',
        'TLS_ALLOW_SELF_SIGNED=false',
        'BACKUP_PATH='.$dir.'/copias',
        // Uno que no es el de la plantilla: con ese la fase 1 falla (V21-1-PL-01).
        'IMAGE_REGISTRY=ghcr.io/acme/kronoqr',
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

it('acepta, avisando, un KIOSK_VLAN_CIDR real que coincide con el ejemplo de la plantilla', function (string $idioma, string $aviso, string $fallo): void {
    // 10.0.20.0/24 es un rango privado corriente: la VLAN de quioscos de un
    // hotel puede ser justo esa. Rechazarla obligaba a falsear el .env para
    // poder instalar (2.2.1, bloque 13). Un valor sin decidir no deja el
    // sistema inalcanzable, solo en el limite general: por eso avisa y sigue.
    $paquete = paqueteDePrueba();
    $plantilla = (string) file_get_contents($paquete['dir'].'/.env.example');
    expect($plantilla)->toMatch('/^KIOSK_VLAN_CIDR=10\.0\.20\.0\/24$/m');

    $env = (string) file_get_contents($paquete['env']);
    $env = (string) preg_replace('/^KIOSK_VLAN_CIDR=.*$/m', 'KIOSK_VLAN_CIDR=10.0.20.0/24', $env);
    file_put_contents($paquete['env'], $env);

    $resultado = ejecutarInstalador(['--check-only', '--compose-file', $paquete['compose']], ['KRONOQR_LANG' => $idioma]);
    $salida = $resultado->getOutput();

    // Sale 0 o 2 segun tenga Docker y root la maquina de pruebas; lo que se fija
    // es que KIOSK_VLAN_CIDR no es uno de los fallos y que el aviso aparece con
    // la orden para comprobarlo.
    $lineasDeFallo = array_filter(
        explode("\n", $salida),
        static fn (string $linea): bool => str_contains($linea, $fallo),
    );

    expect($resultado->getExitCode())->toBeIn([0, 2])
        ->and($salida)->toContain($aviso)
        ->and($salida)->toContain('from_kiosk_vlan')
        ->and(implode("\n", $lineasDeFallo))->not->toContain('KIOSK_VLAN_CIDR');

    borrarPaquete($paquete['dir']);
})->with([
    'espanol' => ['es', '[aviso] KIOSK_VLAN_CIDR=10.0.20.0/24 coincide con el ejemplo de la plantilla', '[FALLA]'],
    'ingles' => ['en', '[warn]  KIOSK_VLAN_CIDR=10.0.20.0/24 matches the template example', '[FAIL]'],
])->group('RF-PD-02');

it('sigue rechazando un KIOSK_VLAN_CIDR vacio', function (): void {
    // Sin la red de quioscos el borde no arranca: eso si es un fallo.
    $paquete = paqueteDePrueba();

    $env = (string) file_get_contents($paquete['env']);
    $env = (string) preg_replace('/^KIOSK_VLAN_CIDR=.*$/m', 'KIOSK_VLAN_CIDR=', $env);
    file_put_contents($paquete['env'], $env);

    $resultado = ejecutarInstalador(['--check-only', '--compose-file', $paquete['compose']]);

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getOutput())->toMatch('/\[FALLA\][^\n]*KIOSK_VLAN_CIDR/');

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
        'CON_COMILLAS_Y_COMENTARIO="/srv/copias" # el destino',
        "CON_COMILLAS_SIMPLES_Y_COMENTARIO='/srv/copias' # el destino",
        'CON_COMILLAS_Y_ALMOHADILLA_DENTRO="/srv/copias # no es comentario"',
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
        ->and($lee('CON_COMILLAS_Y_COMENTARIO'))->toBe('/srv/copias')
        ->and($lee('CON_COMILLAS_SIMPLES_Y_COMENTARIO'))->toBe('/srv/copias')
        ->and($lee('CON_COMILLAS_Y_ALMOHADILLA_DENTRO'))->toBe('/srv/copias # no es comentario')
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

    // AUD-1: la contrasena del migrador (superusuario) no llega al contenedor de la
    // aplicacion. Las migraciones van por el servicio puntual `migrate`.
    expect($script)->toContain('run --rm --no-deps -T migrate php artisan migrate --database=pgsql_migrator --force');
    expect($script)->not->toContain('exec -T app php artisan migrate');
})->group('RF-PD-02');

it('la fase 1 da [FALLA] con el IMAGE_REGISTRY de la plantilla y no llega a verificar firmas (V21-1-PL-01, I2)', function (string $valor): void {
    $paquete = paqueteDePrueba();
    $env = (string) file_get_contents($paquete['dir'].'/.env.example');
    $env = (string) preg_replace('/^IMAGE_REGISTRY=.*$/m', 'IMAGE_REGISTRY='.$valor, $env);
    file_put_contents($paquete['dir'].'/.env', $env);

    $proceso = bashConElInstalador(
        'kq_msg_init es; CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0; '
        .'ENV_FILE='.escapeshellarg($paquete['dir'].'/.env').'; ENV_TEMPLATE='.escapeshellarg($paquete['dir'].'/.env.example').'; '
        .'check_customer_values; printf "plantilla=%s fallos=%s\n" "${REGISTRY_IS_TEMPLATE}" "${CHECKS_FAILED}"'
    );
    borrarPaquete($paquete['dir']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput().$proceso->getErrorOutput())->toContain('IMAGE_REGISTRY sigue con el valor de ejemplo de la plantilla')
        ->and($proceso->getOutput().$proceso->getErrorOutput())->toContain('el valor de la plantilla, y ahi no hay ninguna imagen de KronoQR')
        ->and($proceso->getOutput())->toContain('plantilla=1');

    expect(Repo::contents('infra/scripts/install.sh'))
        ->toContain('[ "${REGISTRY_IS_TEMPLATE}" -eq 1 ] || check_image_signatures');
})->with(['plantilla' => ['ghcr.io/kronoqr'], 'vacio' => ['']])->group('RF-PD-02');

it('una instalacion sin licencia no es un error: license:show con 1 es un aviso que dice como activarla (I7)', function (int $codigo, string $esperado, string $ausente): void {
    $proceso = bashConElInstalador(
        'kq_msg_init es; COMPOSE_FILE=/opt/kronoqr/docker-compose.yml; '
        .'compose() { return '.$codigo.'; }; verify_license_status 2>&1'
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain($esperado)
        ->and($proceso->getOutput())->not->toContain($ausente);
})->with([
    'vigente' => [0, 'Licencia vigente y dentro del plan', 'AVISO'],
    'sin licencia o con algo que mirar' => [3, 'NO es un error de la instalacion', 'ha terminado'],
    'la consulta falla (excepcion no capturada: 1)' => [1, 'codigo inesperado', 'NO es un error de la instalacion'],
    'la consulta falla (otro codigo)' => [2, 'codigo inesperado', 'NO es un error de la instalacion'],
])->group('RF-PD-02', 'RF-PD-05');

it('la vuelta atras retira tambien los padres de BACKUP_PATH que creo y no otros (I9)', function (): void {
    $dir = sys_get_temp_dir().'/kq-ancestro-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $proceso = bashConElInstalador(
        'printf "nuevo=%s\n" "$(first_missing_ancestor '.escapeshellarg($dir.'/var/backups/fichaje').')"; '
        .'printf "existe=%s\n" "$(first_missing_ancestor '.escapeshellarg($dir).')"'
    );
    rmdir($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('nuevo='.$dir.'/var'."\n")
        ->and($proceso->getOutput())->toContain('existe='.$dir."\n");

    $instalador = Repo::contents('infra/scripts/install.sh');
    expect($instalador)->toContain('register_undo "$(kq_format undo_backup_dir "${created_root}")" "rm -rf \'${created_root}\'"')
        // El .env vuelve con su modo original, no con el 0600 de la copia.
        ->and($instalador)->toContain("mv -f '\${backup_env}' '\${ENV_FILE}' && chmod '\${env_mode}' '\${ENV_FILE}'");
})->group('RF-PD-02');

it('la salida final no habla de la 2.0.0 y apunta a las guias del cliente del paquete (I8)', function (): void {
    $dir = sys_get_temp_dir().'/kq-docs-'.bin2hex(random_bytes(4));
    mkdir($dir.'/docs/cliente', 0o755, true);
    touch($dir.'/docker-compose.yml');

    $proceso = bashConElInstalador(
        'kq_msg_init es; OPT_COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; resolve_paths_quietly; '
        .'printf "docs=%s\n" "${DOCS_DIR}"; kq_text done_next; kq_text existing_stop'
    );
    unlink($dir.'/docker-compose.yml');
    rmdir($dir.'/docs/cliente');
    rmdir($dir.'/docs');
    rmdir($dir);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('docs='.$dir.'/docs/cliente')
        ->and($proceso->getOutput())->not->toContain('2.0.0')
        ->and($proceso->getOutput())->not->toContain('a partir de la version 2.1');
})->group('RF-PD-02');

/**
 * Ejecuta pull_images_if_needed de install.sh con un `docker` falso: `$imagen` es
 * la unica que pide el compose y no esta por su digest; `$pull` dice si la
 * descarga funciona y `$repoDigests` lo que da `{{len .RepoDigests}}` de su
 * etiqueta (null = la etiqueta no esta en local).
 *
 * @return array{codigo: int, salida: string, docker: string}
 */
function instalarConImagen(string $imagen, bool $pull, ?int $repoDigests): array
{
    $dir = sys_get_temp_dir().'/kq-digest-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/.env', "IMAGE_REGISTRY=ghcr.io/acme/kronoqr\n");
    touch($dir.'/docker-compose.yml');
    $etiqueta = explode('@', $imagen)[0];

    $proceso = bashConElInstalador(
        'kq_msg_init es; ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; '
        .'PRODUCT_VERSION=2.3.0; KQ_SKIP_SIGNATURE_CHECK=1; LOG='.escapeshellarg($dir.'/docker.log').'; '
        .'docker() { local IFS=" "; printf "%s\n" "$*" >>"${LOG}"; case "$*" in '
        .'*"config --images"*) printf "%s\n" '.escapeshellarg($imagen).' ;; '
        .'"image inspect -f {{len .RepoDigests}} "'.escapeshellarg($etiqueta).') '
        .($repoDigests === null ? 'return 1' : 'printf "%s\n" '.$repoDigests).' ;; '
        .'*"image inspect"*) return 1 ;; '
        .'*pull*) return '.($pull ? '0' : '1').' ;; esac; return 0; }; '
        .'tag_pinned_images() { :; }; '
        .'pull_images_if_needed; echo SIGUE'
    );
    $docker = is_file($dir.'/docker.log') ? (string) file_get_contents($dir.'/docker.log') : '';
    foreach (['.env', 'docker-compose.yml', '.kronoqr-signature-check', 'docker.log'] as $fichero) {
        @unlink($dir.'/'.$fichero);
    }
    @rmdir($dir);

    return ['codigo' => $proceso->getExitCode() ?? -1, 'salida' => $proceso->getOutput().$proceso->getErrorOutput(), 'docker' => $docker];
}

it('una imagen cargada sin su digest y sin internet: intenta descargar y luego nombra su variable (V1-PL-02)', function (string $imagen, string $variable): void {
    $resultado = instalarConImagen($imagen, pull: false, repoDigests: 0);

    expect($resultado['codigo'])->toBe(4, $resultado['salida'])
        // Primero se intenta la descarga: con internet funcionaria y no se toca nada.
        ->and($resultado['docker'])->toContain('pull')
        ->and($resultado['salida'])->toContain('se cargo con \'docker load\'')
        ->and($resultado['salida'])->toContain('la linea vacia '.$variable.'=')
        ->and($resultado['salida'])->not->toContain('docker login');
})->with([
    'php' => ['ghcr.io/acme/kronoqr/php:2.3.0@sha256:'.str_repeat('a', 64), 'IMAGE_DIGEST_PHP'],
    'redis' => ['redis:7-alpine@sha256:'.str_repeat('b', 64), 'REDIS_IMAGE_DIGEST'],
])->group('RF-PD-02');

it('con internet no culpa al docker load: la etiqueta presente (con o sin digest de registro) no impide descargar por digest (V1-PL-02)', function (?int $repoDigests): void {
    $resultado = instalarConImagen('redis:7-alpine@sha256:'.str_repeat('b', 64), pull: true, repoDigests: $repoDigests);

    expect($resultado['codigo'])->toBe(0, $resultado['salida'])
        ->and($resultado['salida'])->toContain('SIGUE')
        ->and($resultado['salida'])->not->toContain('docker load');
})->with([
    'redis de otro proyecto, con su digest' => [1],
    'etiqueta cargada sin digest' => [0],
])->group('RF-PD-02');

it('si la descarga falla y la etiqueta trae digest de registro, es un fallo de descarga, no un docker load (V1-PL-02)', function (): void {
    $resultado = instalarConImagen('redis:7-alpine@sha256:'.str_repeat('b', 64), pull: false, repoDigests: 1);

    expect($resultado['codigo'])->toBe(4, $resultado['salida'])
        ->and($resultado['salida'])->toContain('no se han podido descargar las imagenes')
        ->and($resultado['salida'])->not->toContain('docker load\' en un Docker');
})->group('RF-PD-02');

it('ensena el registro de los servicios que fallan ANTES de deshacer, y los mensajes ya no mandan a docker compose logs (V1-PL-04, R3-PL-04)', function (): void {
    $proceso = bashConElInstalador(
        'kq_msg_init es; compose() { local IFS=" "; printf "COMPOSE %s\n" "$*"; }; show_service_logs nginx'
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getErrorOutput())->toContain('Estado y ultimas lineas del registro de: nginx (antes de deshacer)')
        ->and($proceso->getErrorOutput())->toContain('COMPOSE logs --no-color --tail 40 nginx')
        ->and($proceso->getErrorOutput())->toContain('fin del registro');

    $instalador = Repo::contents('infra/scripts/install.sh');
    $mensajes = Repo::contents('infra/scripts/lib/messages.sh');
    expect($instalador)->toContain("show_service_logs nginx\n    rollback_and_die")
        ->and($instalador)->toContain('rollback_and_die "$(kq_text f_migrating)"')
        // Cada subdirectorio de BACKUP_PATH con su nombre en la lista de lo deshecho.
        ->and($instalador)->toContain('*/metrics) label="$(kq_format undo_metrics_dir "${dir}")" ;;')
        ->and($instalador)->toContain('*/daily | */base) label="$(kq_format undo_backup_dir "${dir}")" ;;');
    foreach (['f_migrating', 'f_waiting', 'f_services_up', 'f_images'] as $clave) {
        preg_match('/^KQ_MSG_ES\['.$clave.'\]="([^\n]*)"$/m', $mensajes, $m);
        expect($m[1] ?? '')->not->toBe('')
            ->and($m[1] ?? '')->not->toContain('docker compose -f %s logs')
            ->and($m[1] ?? '')->not->toContain('docker login %s');
    }
})->group('RF-PD-02');

it('la fase 1 rechaza IMAGE_REGISTRY con el marcador de la guia o con espacios (revision del bloque 7)', function (string $valor): void {
    $paquete = paqueteDePrueba();
    $env = (string) file_get_contents($paquete['dir'].'/.env.example');
    $env = (string) preg_replace('/^IMAGE_REGISTRY=.*$/m', 'IMAGE_REGISTRY='.$valor, $env);
    file_put_contents($paquete['dir'].'/.env', $env);

    $proceso = bashConElInstalador(
        'kq_msg_init es; CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0; '
        .'ENV_FILE='.escapeshellarg($paquete['dir'].'/.env').'; ENV_TEMPLATE='.escapeshellarg($paquete['dir'].'/.env.example').'; '
        .'check_customer_values; printf "plantilla=%s\n" "${REGISTRY_IS_TEMPLATE}"'
    );
    borrarPaquete($paquete['dir']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput().$proceso->getErrorOutput())->toContain('todavia lleva el marcador de la guia')
        ->and($proceso->getOutput())->toContain('plantilla=1');
})->with([
    'marcador' => ['ghcr.io/<cuenta-del-fabricante>/kronoqr'],
    'espacio' => ['"ghcr.io/acme /kronoqr"'],
])->group('RF-PD-02');

it('backup.sh barre lo empezado tambien con un corte de SSH: sale con 129 por su trap', function (): void {
    expect(Repo::contents('infra/scripts/backup.sh'))->toContain("trap 'exit 129' HUP")
        ->and(Repo::contents('infra/scripts/lib/exit-codes.sh'))->toContain('sale con 129 (corte de SSH), 130 o 143');
})->group('RF-PD-02');

it('install.sh documenta --no-drill-schedule, lo programa por omision tras instalar y no deshace nada si falla (V5-DV-04)', function (): void {
    $ayuda = ejecutarInstalador(['--help'])->getOutput();
    $ingles = ejecutarInstalador(['--lang', 'en', '--help'])->getOutput();
    $script = Repo::contents('infra/scripts/install.sh');

    expect($ayuda)->toContain('--no-drill-schedule')
        ->and($ingles)->toContain('--no-drill-schedule')
        // Se programa DESPUES de desarmar la vuelta atras: un fallo no deshace una instalacion hecha.
        ->and(strpos($script, 'kq_drill_schedule_step'))->toBeGreaterThan((int) strpos($script, 'disarm_rollback_traps\n'))
        ->and($script)->toContain('OPT_NO_DRILL=1');
})->group('RNF-D-05', 'RQ-11');
