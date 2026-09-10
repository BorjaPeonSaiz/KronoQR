<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\AlertRules;
use Tests\Architecture\Support\Repo;

/*
 * ALERTMANAGER, RENDERIZADO DE VERDAD (tarea 3.2, decisiones 4, 5 y 6).
 *
 * ## Por que esta prueba EJECUTA el script en vez de leerlo
 *
 * Alertmanager no expande variables de entorno. Por eso `alertmanager.yml` pasa
 * a ser una plantilla y un script POSIX la renderiza al arrancar el contenedor
 * con los destinos del `.env` del cliente. Un `grep` sobre la plantilla no
 * comprobaria nada de lo que puede salir mal ahi:
 *
 *   · que con los tres correos rellenos el resultado siga siendo YAML valido
 *     —una comilla sin cerrar deja a Alertmanager sin arrancar, y entonces las
 *     once alertas se evaluan y ninguna se entrega—;
 *   · que con los destinos VACIOS —la instalacion recien hecha— el resultado
 *     tambien sea valido, porque Alertmanager rechaza un `to:` vacio y se
 *     negaria a arrancar por un campo que el cliente aun no ha rellenado;
 *   · que la contraseña del SMTP del cliente no acabe escrita dentro del fichero
 *     renderizado mas alla de donde tiene que estar.
 *
 * Se ejecuta con un destino en el directorio temporal: el repositorio esta
 * montado de solo lectura dentro del contenedor y nada de la suite escribe en el.
 *
 * ## Y por que se comprueba el enrutado contra las REGLAS
 *
 * La decision 4 fija tres destinatarios y la 5 les da una ruta a cada uno. Las
 * dos mitades se escriben en ficheros distintos —`rules/*.yml` y la plantilla— y
 * nada las relaciona: un `destinatario: soporte` en una regla nueva encajaria
 * con la ruta por defecto y la alerta no llegaria a nadie, en verde y en
 * silencio. Aqui se cierra ese hueco.
 */

/** Los tres destinos de correo con los que se prueba el renderizado completo. */
const CORREOS_DE_ALERTA = [
    'ALERT_EMAIL_IT' => 'it@ejemplo.invalid',
    'ALERT_EMAIL_RRHH' => 'rrhh@ejemplo.invalid',
    'ALERT_EMAIL_SEGURIDAD' => 'seguridad@ejemplo.invalid',
];

/** La contraseña del SMTP del cliente, para comprobar que no se filtra. */
const CLAVE_SMTP_DE_PRUEBA = 'clave-smtp-que-no-debe-aparecer';

/**
 * El entorno completo del renderizador, con todas las variables declaradas.
 *
 * Se pasan SIEMPRE las nueve `ALERT_*` y las `MAIL_*`, tambien cuando el caso
 * las quiere vacias: el contenedor de la aplicacion trae las suyas del `.env` de
 * desarrollo, y heredarlas convertiria el caso «instalacion recien hecha» en
 * otro caso distinto sin que se notara.
 *
 * @param  array<string, string>  $sobrescrituras
 * @return array<string, string>
 */
function entornoDeAlertas(array $sobrescrituras = []): array
{
    return array_merge([
        'ALERT_EMAIL_IT' => '',
        'ALERT_EMAIL_RRHH' => '',
        'ALERT_EMAIL_SEGURIDAD' => '',
        'ALERT_WEBHOOK_IT' => '',
        'ALERT_WEBHOOK_RRHH' => '',
        'ALERT_WEBHOOK_SEGURIDAD' => '',
        'ALERT_MAINTENANCE_WEEKDAY' => 'sunday',
        'ALERT_MAINTENANCE_START' => '02:00',
        'ALERT_MAINTENANCE_END' => '04:00',
        'MAIL_HOST' => '',
        'MAIL_PORT' => '587',
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
        'MAIL_FROM_ADDRESS' => 'kronoqr@ejemplo.invalid',
    ], $sobrescrituras);
}

/**
 * Renderiza la configuracion con el script real y devuelve el YAML ya parseado.
 *
 * @param  array<string, string>  $entorno
 * @return array{code: int, stderr: string, yaml: string}
 */
function renderizadorEjecutado(array $entorno): array
{
    $script = Repo::file('infra/observability/alertmanager/render-config.sh');

    expect(is_file($script))->toBeTrue(
        'No existe infra/observability/alertmanager/render-config.sh. Alertmanager no expande '
        .'variables de entorno: sin el renderizador, los destinatarios del cliente tendrian que '
        .'escribirse en el repositorio (regla dura 13).'
    );

    // El repositorio esta montado de solo lectura dentro del contenedor: la
    // plantilla se lee de ahi y todo lo que el script escribe —el YAML y los
    // ficheros de secreto— va a un directorio temporal propio de esta
    // ejecucion. `ALERTMANAGER_RENDER_ONLY` es lo que evita que el script ceda
    // el proceso a un Alertmanager real escuchando en un puerto.
    $trabajo = sys_get_temp_dir().'/kronoqr-alertmanager-'.bin2hex(random_bytes(8));
    $destino = $trabajo.'/alertmanager.yml';

    $proceso = new Process(
        ['sh', $script],
        env: array_merge($entorno, [
            'ALERTMANAGER_TEMPLATE_FILE' => Repo::file('infra/observability/alertmanager/alertmanager.yml.template'),
            'ALERTMANAGER_OUTPUT_FILE' => $destino,
            'ALERTMANAGER_SECRET_DIR' => $trabajo.'/secrets',
            'ALERTMANAGER_RENDER_ONLY' => '1',
        ]),
        timeout: 30.0,
    );
    $proceso->run();

    $yaml = is_file($destino) ? (string) file_get_contents($destino) : '';

    // Con guardas y no con `@`: los casos hostiles hacen que el script muera
    // ANTES de crear nada, y un borrado de lo que no existe deja la prueba en
    // amarillo por un aviso de PHP que no dice nada del sistema bajo prueba.
    // Una suite con avisos aceptados es una suite en la que el siguiente aviso
    // —el que si importa— no lo mira nadie.
    foreach (glob($trabajo.'/secrets/*') ?: [] as $secreto) {
        unlink($secreto);
    }

    if (is_file($destino)) {
        unlink($destino);
    }

    foreach ([$trabajo.'/secrets', $trabajo] as $directorio) {
        if (is_dir($directorio)) {
            rmdir($directorio);
        }
    }

    return [
        'code' => $proceso->getExitCode() ?? -1,
        'stderr' => $proceso->getErrorOutput().$proceso->getOutput(),
        'yaml' => $yaml,
    ];
}

/**
 * Renderiza con el script real y devuelve el YAML ya parseado, exigiendo que la
 * ejecucion haya ido bien.
 *
 * @param  array<string, string>  $entorno
 * @return array<string, mixed>
 */
function alertmanagerRenderizado(array $entorno): array
{
    // Memoria por entorno. Cuatro de estas pruebas afirman cosas distintas
    // sobre el MISMO renderizado —el enrutado, la ventana, la inhibicion y los
    // destinatarios—, y arrancar un proceso por cada una añadia tres segundos a
    // la etapa de arquitectura sin comprobar nada nuevo. Cada entorno distinto
    // sigue ejecutando el script de verdad.
    /** @var array<string, array<string, mixed>> $renderizados */
    static $renderizados = [];

    $clave = (string) json_encode($entorno);

    if (isset($renderizados[$clave])) {
        return $renderizados[$clave];
    }

    $ejecucion = renderizadorEjecutado($entorno);

    expect($ejecucion['code'])->toBe(0, 'render-config.sh ha fallado: '.$ejecucion['stderr']);
    expect($ejecucion['yaml'])->not->toBe('', 'render-config.sh no ha escrito el fichero de salida.');

    $contenido = $ejecucion['yaml'];

    /** @var array<string, mixed> $documento */
    $documento = Yaml::parse($contenido);
    $renderizados[$clave] = $documento;

    expect($documento)->toBeArray('El renderizado no es un YAML valido: Alertmanager no arrancaria.');
    // El mensaje va en `toBeFalse` y no en `toContain`: Pest trata el segundo
    // argumento de `toContain` como otro fragmento que buscar.
    expect(str_contains($contenido, CLAVE_SMTP_DE_PRUEBA))->toBeFalse(
        'La contraseña del SMTP del cliente aparece en el fichero renderizado (§7.7).'
    );

    return $documento;
}

/**
 * Los receptores del documento renderizado, indexados por nombre.
 *
 * @param  array<string, mixed>  $documento
 * @return array<string, array<string, mixed>>
 */
function receptoresDe(array $documento): array
{
    /** @var list<array{name?: string}> $receptores */
    $receptores = \is_array($documento['receivers'] ?? null) ? $documento['receivers'] : [];

    $porNombre = [];

    foreach ($receptores as $receptor) {
        /** @var array<string, mixed> $receptor */
        $nombre = $receptor['name'] ?? null;

        $porNombre[\is_string($nombre) ? $nombre : ''] = $receptor;
    }

    return $porNombre;
}

/**
 * Las rutas de primer nivel del arbol de enrutado.
 *
 * @param  array<string, mixed>  $documento
 * @return list<array<string, mixed>>
 */
function rutasDe(array $documento): array
{
    /** @var array<string, mixed> $ruta */
    $ruta = \is_array($documento['route'] ?? null) ? $documento['route'] : [];

    /** @var list<array<string, mixed>> $rutas */
    $rutas = \is_array($ruta['routes'] ?? null) ? $ruta['routes'] : [];

    return $rutas;
}

/**
 * Los componentes que quedan mudos dentro de la ventana semanal declarada.
 *
 * Da igual que el enrutado use una ruta por componente (`component = "kiosk"`) o
 * una sola con alternativa (`component =~ "kiosk|api|tls|host"`): lo que
 * interesa es el CONJUNTO, porque el modo de fallo —una familia que no puede
 * quedar muda y queda muda— es el mismo con las dos formas.
 *
 * @param  array<string, mixed>  $documento
 * @return list<string>
 */
function componentesSilenciados(array $documento): array
{
    $componentes = [];

    foreach (rutasDe($documento) as $ruta) {
        if (($ruta['mute_time_intervals'] ?? null) === null) {
            continue;
        }

        /** @var list<string> $emparejadores */
        $emparejadores = \is_array($ruta['matchers'] ?? null) ? $ruta['matchers'] : [];

        preg_match_all('/component\s*=~?\s*"?([a-z|]+)"?/', implode(' ', $emparejadores), $coincidencias);

        foreach ($coincidencias[1] as $expresion) {
            $componentes = array_merge($componentes, explode('|', $expresion));
        }
    }

    return array_values(array_unique($componentes));
}

/**
 * Los componentes que calla la ventana automatica del actualizador.
 *
 * @param  array<string, mixed>  $documento
 * @return list<string>
 */
function componentesInhibidosPorMantenimiento(array $documento): array
{
    /** @var list<array<string, mixed>> $inhibiciones */
    $inhibiciones = \is_array($documento['inhibit_rules'] ?? null) ? $documento['inhibit_rules'] : [];

    $componentes = [];

    foreach ($inhibiciones as $regla) {
        $origen = (string) json_encode($regla['source_matchers'] ?? []);

        if (! str_contains($origen, 'VentanaDeMantenimientoActiva')) {
            continue;
        }

        preg_match_all(
            '/component\s*=~?\s*\\\\?"?([a-z|]+)/',
            (string) json_encode($regla['target_matchers'] ?? []),
            $coincidencias,
        );

        foreach ($coincidencias[1] as $expresion) {
            $componentes = array_merge($componentes, explode('|', $expresion));
        }
    }

    return array_values(array_unique($componentes));
}

/**
 * El bloque del servicio `alertmanager` de un compose, sin los servicios
 * vecinos.
 *
 * Se acota a proposito: `env_file` aparece en casi todos los servicios del
 * fichero, asi que buscarlo en el compose entero daria por bueno cualquier
 * estado. Lo que se afirma es lo que recibe ESE contenedor.
 */
function servicioAlertmanager(string $compose): string
{
    $encontrado = preg_match(
        '/^  alertmanager:\n(?:.*\n)*?(?=^  [a-z#]|\z)/m',
        str_replace("\r\n", "\n", Repo::contents($compose)),
        $partes,
    );

    expect($encontrado)->toBe(1, 'No se encuentra el servicio alertmanager en '.$compose.'.');

    return $partes[0] ?? '';
}

it('conserva la plantilla y el renderizador que sustituyen al fichero fijo', function (): void {
    // Un `alertmanager.yml` fijo en el repositorio solo puede tener una de dos
    // cosas: destinos vacios —y entonces no avisa a nadie— o el correo del
    // cliente dentro del repositorio del fabricante (regla dura 13). La
    // plantilla mas el renderizador es lo que evita elegir entre las dos.
    expect(is_file(Repo::file('infra/observability/alertmanager/alertmanager.yml.template')))->toBeTrue(
        'Falta alertmanager.yml.template (decision 5 de la ficha 3.2).'
    );
    expect(is_file(Repo::file('infra/observability/alertmanager/render-config.sh')))->toBeTrue(
        'Falta render-config.sh (decision 5 de la ficha 3.2).'
    );
})->group('RF-PD-01');

it('entrega a los tres destinatarios por correo cuando el cliente los ha rellenado', function (): void {
    // El caso de la instalacion configurada. Cada receptor entrega EN SU
    // direccion: un `to:` cruzado manda a RRHH la rotura de la cadena de
    // auditoria y al responsable de seguridad los turnos sin cerrar, y ninguna
    // de las dos personas puede hacer nada con lo que recibe.
    $documento = alertmanagerRenderizado(entornoDeAlertas(array_merge(CORREOS_DE_ALERTA, [
        'MAIL_HOST' => 'smtp.ejemplo.invalid',
        'MAIL_USERNAME' => 'kronoqr',
        'MAIL_PASSWORD' => CLAVE_SMTP_DE_PRUEBA,
    ])));

    $receptores = receptoresDe($documento);

    expect(array_keys($receptores))->toContain('it-cliente', 'rrhh', 'seguridad', 'silencio');

    foreach ([
        'it-cliente' => CORREOS_DE_ALERTA['ALERT_EMAIL_IT'],
        'rrhh' => CORREOS_DE_ALERTA['ALERT_EMAIL_RRHH'],
        'seguridad' => CORREOS_DE_ALERTA['ALERT_EMAIL_SEGURIDAD'],
    ] as $nombre => $direccion) {
        /** @var list<array{to?: string}> $entregas */
        $entregas = \is_array($receptores[$nombre]['email_configs'] ?? null)
            ? $receptores[$nombre]['email_configs']
            : [];

        expect($entregas)->not->toBeEmpty($nombre.' no tiene entrega por correo.');
        expect($entregas[0]['to'] ?? '')->toBe($direccion);
    }
})->group('RF-PD-01', 'RS-08');

it('sigue siendo valido con los destinos vacios, que es como llega la instalacion', function (): void {
    // El dia de la instalacion las seis variables de destino estan vacias.
    // Alertmanager RECHAZA un `to:` vacio y no arranca; con el contenedor caido,
    // Prometheus evalua las once alertas y ninguna se entrega — y el perfil de
    // observabilidad figura encendido. Es el fallo mas caro de los tres porque
    // parece que todo esta bien.
    $documento = alertmanagerRenderizado(entornoDeAlertas());

    $receptores = receptoresDe($documento);

    expect(array_keys($receptores))->toContain('it-cliente', 'rrhh', 'seguridad', 'silencio');

    foreach (['it-cliente', 'rrhh', 'seguridad', 'silencio'] as $nombre) {
        // `toHaveKey` toma su segundo argumento como el VALOR esperado, no como
        // el mensaje del fallo: el texto va en `toBeFalse`.
        expect(\array_key_exists('email_configs', $receptores[$nombre] ?? []))->toBeFalse(
            $nombre.' declara una entrega por correo sin direccion: Alertmanager no arrancaria.'
        );
    }
})->group('RF-PD-01');

it('añade la entrega por webhook solo al destinatario que la tiene configurada', function (): void {
    // El webhook es opcional y por destinatario: un hotel manda las alertas del
    // IT a su sistema de tickets y las de RRHH solo por correo. Si el
    // renderizador lo pusiera en los tres, la rotura de la cadena de auditoria
    // —que es un incidente de seguridad, RL-15— saldria hacia un sistema de
    // terceros que nadie ha autorizado.
    $documento = alertmanagerRenderizado(entornoDeAlertas([
        'ALERT_WEBHOOK_IT' => 'https://tickets.ejemplo.invalid/kronoqr',
    ]));

    $receptores = receptoresDe($documento);

    /** @var list<array{url?: string, url_file?: string}> $webhooks */
    $webhooks = \is_array($receptores['it-cliente']['webhook_configs'] ?? null)
        ? $receptores['it-cliente']['webhook_configs']
        : [];

    expect($webhooks)->not->toBeEmpty('it-cliente no recibe el webhook configurado.');

    // DECISION 17(g). Una URL de webhook es un secreto PORTADOR: quien la tiene
    // publica alertas en el sistema del cliente. Iba en claro dentro de un YAML
    // 0644 mientras la contraseña del SMTP se guardaba aparte en 0600, que es
    // proteger la cerradura y dejar la llave puesta. Va por `url_file`, con el
    // mismo tratamiento.
    expect($webhooks[0]['url_file'] ?? '')->not->toBe('', 'El webhook no se entrega por fichero.');
    expect(\array_key_exists('url', $webhooks[0]))->toBeFalse(
        'La URL del webhook sigue escrita dentro del YAML.'
    );

    expect($receptores['rrhh'] ?? [])->not->toHaveKey('webhook_configs');
    expect($receptores['seguridad'] ?? [])->not->toHaveKey('webhook_configs');
})->group('RF-PD-01', 'RS-08');

it('no escribe la URL del webhook dentro del fichero de configuracion', function (): void {
    // La otra mitad de 17(g), sobre el TEXTO renderizado y no sobre el YAML ya
    // interpretado: `url_file` en la clave y la URL en un comentario sería el
    // mismo problema con otra forma. El fichero de configuracion lo lee
    // cualquiera que entre al contenedor; el de secretos, no.
    $ejecucion = renderizadorEjecutado(entornoDeAlertas([
        'ALERT_WEBHOOK_SEGURIDAD' => 'https://soc.ejemplo.invalid/hook/t0ken-portador',
    ]));

    expect($ejecucion['code'])->toBe(0, $ejecucion['stderr']);
    expect(str_contains($ejecucion['yaml'], 't0ken-portador'))->toBeFalse(
        'La URL del webhook aparece en el alertmanager.yml renderizado (§7.7).'
    );
})->group('RS-08');

it('encamina cada destinatario a su receptor y la severidad informativa al silencio', function (): void {
    // Sin una ruta por destinatario, todo cae en el receptor por defecto y los
    // tres destinatarios del catalogo se convierten en uno. Y sin la ruta de
    // `severity: info`, la ventana de mantenimiento —que existe para INHIBIR y
    // no para avisar (decision 6)— notificaria cada vez que se actualiza el
    // producto, que es justo el momento en el que nadie quiere una alerta.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    $destinos = [];

    foreach (rutasDe($documento) as $ruta) {
        /** @var list<string> $emparejadores */
        $emparejadores = \is_array($ruta['matchers'] ?? null) ? $ruta['matchers'] : [];
        $receptor = $ruta['receiver'] ?? null;

        foreach ($emparejadores as $emparejador) {
            $destinos[$emparejador] = \is_string($receptor) ? $receptor : '';
        }
    }

    expect($destinos)->toHaveKey('destinatario = "it-cliente"');
    expect($destinos)->toHaveKey('destinatario = "rrhh"');
    expect($destinos)->toHaveKey('destinatario = "seguridad"');

    expect($destinos['destinatario = "it-cliente"'])->toBe('it-cliente');
    expect($destinos['destinatario = "rrhh"'])->toBe('rrhh');
    expect($destinos['destinatario = "seguridad"'])->toBe('seguridad');
    expect($destinos['severity = "info"'] ?? '')->toBe('silencio');
})->group('RF-PD-01', 'RF-PD-10');

it('silencia en la ventana de mantenimiento los componentes que se reinician, y ninguno mas', function (): void {
    // DECISION 6(b), y las dos mitades importan igual. Silenciar quiosco, API,
    // TLS y anfitrion durante el mantenimiento declarado evita la avalancha de
    // un reinicio previsto. Silenciar copia, integridad, auditoria,
    // autenticacion o incidencias durante esa misma ventana esconderia
    // exactamente lo que hay que ver mientras alguien tiene las manos dentro del
    // servidor: una copia que falla antes de actualizar (RF-PD-10) o una rotura
    // de la cadena de auditoria.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    $silenciables = ['kiosk', 'api', 'tls', 'host'];
    $silenciados = componentesSilenciados($documento);

    // Sin esta linea, un enrutado que se dejara la ventana sin aplicar pasaria
    // la prueba: no habria ninguna ruta muda que revisar.
    expect($silenciados)->not->toBeEmpty('Ninguna ruta aplica la ventana de mantenimiento.');

    expect(array_values(array_unique(array_diff($silenciados, $silenciables))))->toBe(
        [],
        'La ventana de mantenimiento silencia componentes que nunca debe silenciar.'
    );
})->group('RF-PD-10');

it('inhibe las alertas de reinicio mientras el actualizador declara mantenimiento', function (): void {
    // DECISION 6(c). La ventana semanal declarada no cubre una actualizacion
    // fuera de horario, y `update.sh` corre en el anfitrion, donde Alertmanager
    // no publica puerto. La marca `.prom` que escribe el actualizador enciende
    // `VentanaDeMantenimientoActiva`, y esta regla de inhibicion es lo que
    // convierte esa alerta informativa en silencio para los cuatro componentes
    // que se reinician. Sin ella, cada actualizacion despierta al IT del cliente.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    /** @var list<array<string, mixed>> $inhibiciones */
    $inhibiciones = \is_array($documento['inhibit_rules'] ?? null) ? $documento['inhibit_rules'] : [];

    // Sin espacios ni comillas: lo que importa es la regla, no si esta escrita
    // `component=~"kiosk"` o `component =~ "kiosk"`. Las dos son validas para
    // Alertmanager y una prueba que distinga entre ellas mide el estilo.
    $texto = str_replace([' ', '"', '\\'], '', (string) json_encode($inhibiciones, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    expect($texto)->toContain('alertname=VentanaDeMantenimientoActiva');
    expect($texto)->toContain('component=~kiosk|api|tls|host');
})->group('RF-PD-10');

it('toma el horario de la ventana de mantenimiento del entorno del cliente', function (): void {
    // Regla dura 13. El cambio de turno de las 06:00 no puede caer dentro de la
    // ventana, y ese horario es de cada hotel: si estuviera escrito en el
    // repositorio, venderle a un cliente con otro turno exigiria tocar el codigo.
    $documento = alertmanagerRenderizado(entornoDeAlertas(array_merge(CORREOS_DE_ALERTA, [
        'ALERT_MAINTENANCE_WEEKDAY' => 'wednesday',
        'ALERT_MAINTENANCE_START' => '01:30',
        'ALERT_MAINTENANCE_END' => '03:15',
    ])));

    $texto = json_encode($documento['time_intervals'] ?? [], JSON_UNESCAPED_SLASHES);

    expect($texto)->toContain('wednesday');
    expect($texto)->toContain('01:30');
    expect($texto)->toContain('03:15');
})->group('RF-PD-01');

it('no deja ningun destinatario de las reglas sin receptor en Alertmanager', function (): void {
    // DECISION 4, comprobada de un fichero contra el otro. Las reglas y el
    // enrutado se escriben por separado y nada los relaciona: una regla nueva
    // con `destinatario: soporte` pasaria la norma del §8.4 —tiene destinatario—
    // y su alerta caeria en el receptor por defecto. Escrita, probada,
    // encaminada y muda.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    $receptores = array_keys(receptoresDe($documento));

    $huerfanos = array_values(array_unique(array_diff(
        array_column(AlertRules::all(), 'destinatario'),
        $receptores,
    )));

    expect($huerfanos)->toBe([], 'Hay destinatarios en las reglas que no tienen receptor.');
})->group('RF-PD-01', 'RF-PR-04');

it('declara las nueve variables de alerta en el .env.example, vacias las de destino', function (): void {
    // §7.7 y regla dura 13: los destinos son del cliente y nacen VACIOS en el
    // repositorio del fabricante. Y declarados: una variable que no figura en el
    // `.env.example` no la conoce nadie, no la documenta `configuracion.md` y la
    // instalacion se queda con las alertas sin destinatario sin saber por que.
    $entorno = Repo::contents('.env.example');

    foreach ([
        'ALERT_EMAIL_IT',
        'ALERT_EMAIL_RRHH',
        'ALERT_EMAIL_SEGURIDAD',
        'ALERT_WEBHOOK_IT',
        'ALERT_WEBHOOK_RRHH',
        'ALERT_WEBHOOK_SEGURIDAD',
    ] as $variable) {
        expect($entorno)->toMatch(
            '/^'.$variable.'=\s*$/m',
            $variable.' tiene que estar declarada y vacia en .env.example.'
        );
    }

    // Las tres de la ventana si traen valor de serie: domingo 02:00-04:00, que
    // es una decision del producto y no un dato del cliente.
    expect($entorno)->toMatch('/^ALERT_MAINTENANCE_WEEKDAY=sunday\s*$/m');
    expect($entorno)->toMatch('/^ALERT_MAINTENANCE_START=02:00\s*$/m');
    expect($entorno)->toMatch('/^ALERT_MAINTENANCE_END=04:00\s*$/m');
})->group('RF-PD-01', 'RS-08');

it('arranca Alertmanager por el renderizador en desarrollo y en produccion', function (): void {
    // El script solo sirve de algo si lo ejecuta el contenedor. Con el
    // `entrypoint` sin cambiar, Alertmanager leeria la plantilla literal —con
    // los marcadores sin sustituir— y no arrancaria; y en desarrollo no habria
    // forma de ver el enrutado antes de que lo vea el cliente (decision 15).
    foreach (['infra/compose.dev.yaml', 'infra/compose.prod.yaml'] as $compose) {
        expect(str_contains(Repo::contents($compose), 'render-config.sh'))->toBeTrue(
            $compose.' no arranca Alertmanager por el renderizador.'
        );
    }
})->group('RF-PD-01');

it('no mete el .env entero del cliente en el contenedor de Alertmanager', function (string $compose): void {
    // DECISION 17(a), y es el bloqueante mas grave de los tres. `env_file: .env`
    // —heredado de la 1.18— entregaba a un binario de terceros, que necesita
    // quince variables, el fichero COMPLETO: `QR_SIGNING_KEY_CURRENT`,
    // `IDENTITY_PIN_SEALING_SECRET_KEY`, `BACKUP_ENCRYPTION_KEY`, `APP_KEY`,
    // `DB_PASSWORD`, `LICENSE_KEY`. Legibles en `docker inspect` y en
    // `/proc/1/environ`, sin exploit ninguno.
    //
    // Con esa puerta abierta, el fichero 0600 de la contraseña SMTP de la
    // decision 5 no protegia nada: la clave de firma del QR estaba al lado, en
    // claro. Las quince variables se nombran una a una, que es la unica forma
    // de que añadir un secreto al `.env` no se lo regale a este contenedor.
    $servicio = servicioAlertmanager($compose);

    expect($servicio)->not->toMatch(
        '/^\s+env_file:/m',
        $compose.': el servicio alertmanager vuelve a recibir el .env completo.'
    );
    expect($servicio)->toMatch('/^\s+environment:/m');

    foreach ([
        'ALERT_EMAIL_IT', 'ALERT_EMAIL_RRHH', 'ALERT_EMAIL_SEGURIDAD',
        'ALERT_WEBHOOK_IT', 'ALERT_WEBHOOK_RRHH', 'ALERT_WEBHOOK_SEGURIDAD',
        'ALERT_MAINTENANCE_WEEKDAY', 'ALERT_MAINTENANCE_START', 'ALERT_MAINTENANCE_END',
        'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_SCHEME',
    ] as $variable) {
        expect(str_contains($servicio, $variable))->toBeTrue(
            $compose.': el servicio alertmanager no recibe '.$variable.', que el renderizador lee.'
        );
    }

    // Y ningun secreto del producto por la puerta de al lado.
    foreach (['QR_SIGNING_KEY', 'IDENTITY_PIN_SEALING', 'BACKUP_ENCRYPTION_KEY', 'APP_KEY', 'DB_PASSWORD', 'LICENSE_KEY'] as $secreto) {
        expect(str_contains($servicio, $secreto))->toBeFalse(
            $compose.': '.$secreto.' no tiene nada que hacer en el entorno de Alertmanager.'
        );
    }
})->with(['infra/compose.dev.yaml', 'infra/compose.prod.yaml'])->group('RS-08', 'RF-PD-01');

it('escapa la comilla simple de una direccion legal en vez de romper el YAML', function (): void {
    // DECISION 17(b). `o'brien@hotel.example` es una direccion legal (RFC 5322) y
    // un apellido corriente. Sin duplicar la comilla, el YAML salia invalido, el
    // script terminaba con codigo 0 y Alertmanager entraba en bucle de reinicio:
    // el perfil de observabilidad figuraba encendido y NINGUNA de las alertas se
    // entregaba. Un fallo mudo, que es la peor clase.
    $ejecucion = renderizadorEjecutado(entornoDeAlertas([
        'ALERT_EMAIL_RRHH' => "o'brien@a.b",
    ]));

    expect($ejecucion['code'])->toBe(0, $ejecucion['stderr']);
    expect($ejecucion['yaml'])->toContain("'o''brien@a.b'");

    /** @var array<string, mixed> $documento */
    $documento = Yaml::parse($ejecucion['yaml']);
    $receptores = receptoresDe($documento);

    /** @var list<array{to?: string}> $entregas */
    $entregas = \is_array($receptores['rrhh']['email_configs'] ?? null)
        ? $receptores['rrhh']['email_configs']
        : [];

    expect($entregas[0]['to'] ?? '')->toBe("o'brien@a.b");
})->group('RF-PD-01');

it('rechaza con el nombre de la variable cualquier valor con un salto de linea', function (): void {
    // DECISION 17(b), la mitad de seguridad. Con un salto de linea dentro de una
    // variable se podia AÑADIR configuracion: un destinatario mas y un webhook
    // de exfiltracion, verificado con `amtool` (`4 receivers`, `SUCCESS`). Quien
    // edita el `.env` es el IT del cliente, pero un `.env` generado por otra
    // herramienta o pegado desde un correo es un vector real.
    //
    // Rechazar con `die` y nombrando la variable convierte una inyeccion
    // silenciosa en un contenedor que no arranca y dice por que.
    $ejecucion = renderizadorEjecutado(entornoDeAlertas([
        'ALERT_EMAIL_IT' => "it@a.b'\n      - to: 'ladron@evil.invalid",
    ]));

    expect($ejecucion['code'])->not->toBe(0, 'El renderizador ha aceptado un valor con salto de linea.');
    expect($ejecucion['stderr'])->toContain('ALERT_EMAIL_IT');
})->group('RS-08');

it('rechaza una hora de mantenimiento que no existe', function (): void {
    // DECISION 17(b). `29:59` pasaba la validacion antigua (`[0-2][0-9]`) y
    // Alertmanager la rechaza al arrancar: otra vez el bucle de reinicio mudo.
    // La hora es un dato que teclea una persona en el `.env` a las tantas.
    $ejecucion = renderizadorEjecutado(entornoDeAlertas(['ALERT_MAINTENANCE_START' => '29:59']));

    expect($ejecucion['code'])->not->toBe(0, 'El renderizador ha aceptado las 29:59 como hora.');
    expect($ejecucion['stderr'])->toContain('ALERT_MAINTENANCE_START');
})->group('RF-PD-01');

it('no deja que una almohadilla ni unos dos puntos inventen claves en el YAML', function (): void {
    // DECISION 17(b). Los dos caracteres que cambian el significado de una linea
    // de YAML: `#` abre un comentario y `: ` abre una clave. Con el valor
    // entrecomillado y la comilla duplicada, los dos son texto. Se cuentan los
    // receptores porque es lo que delata una clave inventada: si el documento
    // gana estructura, deja de haber cuatro.
    $ejecucion = renderizadorEjecutado(entornoDeAlertas([
        'ALERT_EMAIL_IT' => 'it+etiqueta#uno@a.b',
        'ALERT_EMAIL_RRHH' => 'rrhh@a.b: no',
    ]));

    expect($ejecucion['code'])->toBe(0, $ejecucion['stderr']);

    /** @var array<string, mixed> $documento */
    $documento = Yaml::parse($ejecucion['yaml']);

    expect(receptoresDe($documento))->toHaveCount(4);
})->group('RF-PD-01');

it('acota al quiosco la inhibicion de lo critico sobre lo grave', function (): void {
    // DECISION 17(d), y es el fallo mas caro de los que encontro la revision.
    // La regla `critical -> warning` con `equal: ["site"]` parecia acotada y no
    // lo estaba: NINGUNA regla del producto emite la etiqueta `site`, y
    // Alertmanager considera IGUALES dos etiquetas ausentes. Resultado, un
    // certificado TLS caducado apagaba `TurnoAbiertoProlongado`, las tres de
    // `auth.yml` y los cuatro vigilantes de silencio — comprobado contra la
    // imagen real.
    //
    // La regla dice ahora lo que su comentario siempre dijo: un quiosco no
    // despierta a nadie mientras el centro entero esta caido.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    /** @var list<array<string, mixed>> $inhibiciones */
    $inhibiciones = \is_array($documento['inhibit_rules'] ?? null) ? $documento['inhibit_rules'] : [];

    $porSeveridad = array_values(array_filter(
        $inhibiciones,
        static fn (array $regla): bool => str_contains(
            (string) json_encode($regla['source_matchers'] ?? []),
            'severity'
        ),
    ));

    expect($porSeveridad)->not->toBeEmpty('Ha desaparecido la inhibicion de critical sobre warning.');

    $texto = str_replace([' ', '"', '\\'], '', (string) json_encode($porSeveridad[0]));

    expect($texto)->toContain('component=kiosk');
    expect(\array_key_exists('equal', $porSeveridad[0]))->toBeFalse(
        'La inhibicion vuelve a casar por `equal`: con una etiqueta que nadie emite, apaga TODAS las warning del producto.'
    );
})->group('RF-PR-01', 'RS-07');

it('no silencia ni inhibe nunca el enrutado de alertas ni lo que hay que ver durante un mantenimiento', function (): void {
    // DECISION 17(c) y 17(d). `alerting.yml` vigila a Alertmanager: si esa
    // alerta se pudiera inhibir o silenciar, el vigilante del vigilante seria
    // exactamente igual de mudo que lo que vigila. Y ninguna de las familias que
    // llevan consecuencia legal o de datos —copia, auditoria, integridad,
    // autenticacion, incidencias, errores— puede quedar muda dentro de la
    // ventana: son justo las que hay que ver mientras alguien tiene las manos
    // dentro del servidor.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    $intocables = ['alerting', 'backup', 'audit', 'auth', 'incidents', 'projection', 'errors'];

    $silenciados = componentesSilenciados($documento);
    $inhibidos = componentesInhibidosPorMantenimiento($documento);

    expect(array_values(array_intersect($silenciados, $intocables)))->toBe(
        [],
        'La ventana de mantenimiento silencia una familia que nunca puede quedar muda.'
    );
    expect(array_values(array_intersect($inhibidos, $intocables)))->toBe(
        [],
        'La ventana automatica del actualizador inhibe una familia que nunca puede quedar muda.'
    );
})->group('RF-PD-10', 'RS-07');

it('agrupa las alertas de quiosco por alerta, para que cinco tablets sean un solo aviso', function (): void {
    // DECISION 17(e). El §8.4 promete que «cinco quioscos a la vez llegan como
    // una sola notificacion», y con `group_by: [alertname, site, device]` eso era
    // FALSO: cinco dispositivos son cinco grupos y cinco correos, que es
    // exactamente la fatiga que la promesa decia evitar. La ruta de
    // `component=kiosk` agrupa por `alertname` y los cinco `device` viajan
    // dentro del mismo aviso.
    $documento = alertmanagerRenderizado(entornoDeAlertas(CORREOS_DE_ALERTA));

    $rutaDelQuiosco = [];

    foreach (rutasDe($documento) as $ruta) {
        /** @var list<string> $emparejadores */
        $emparejadores = \is_array($ruta['matchers'] ?? null) ? $ruta['matchers'] : [];

        if (str_contains(implode(' ', $emparejadores), 'component = "kiosk"')) {
            $rutaDelQuiosco = $ruta;
        }
    }

    expect($rutaDelQuiosco)->not->toBe([], 'No hay ruta propia para component=kiosk.');
    expect($rutaDelQuiosco['group_by'] ?? [])->toBe(['alertname']);
})->group('RF-AT-10');

it('da a node-exporter en desarrollo el sistema de ficheros del anfitrion', function (): void {
    // DECISION 3, fichero `host.yml`: sin `--collector.filesystem` y
    // `--path.rootfs=/host`, `node_filesystem_avail_bytes` no existe, la alerta
    // de disco no se puede verificar en vivo antes de entregarla y
    // `MetricasDelAnfitrionAusentes` estaria encendida siempre en desarrollo.
    // Produccion ya lo tenia; desarrollo no, y esa diferencia es la que hace que
    // una alerta se pruebe por primera vez en el servidor de un hotel.
    expect(Repo::contents('infra/compose.dev.yaml'))
        ->toContain('--collector.filesystem')
        ->toContain('--path.rootfs=/host');
})->group('RNF-D-01');
