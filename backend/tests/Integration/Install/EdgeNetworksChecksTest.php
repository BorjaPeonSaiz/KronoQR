<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Las redes del borde y los valores del cliente, comprobados por los scripts
 * (I1, I3, PP-01, PP-03, R0; 2.2.0).
 *
 * ## Que se ejecuta y que no
 *
 * Todo lo de aqui corre sin Docker ni root: la fase 1 de `install.sh` sobre un
 * paquete de pruebas, y las funciones de `lib/checks.sh` y `doctor.sh` cargadas
 * con `source` y probadas con un `.env` de juguete. Lo que exige una maquina con
 * Docker —el bucle de reinicio de Redis de verdad, el borde con
 * `TRUSTED_PROXY_CIDR`— lo cubren la etapa ⑧ y `make nginx-smoke`.
 *
 * ## Por que existe
 *
 * El primer despliegue real dio un 403 a toda la plantilla porque ningun control
 * miraba el rango del portal (PP-01), y la guia mandaba poner un
 * `METRICS_ALLOW_CIDR` que el instalador rechazaba (I1).
 */

/**
 * Arma un paquete minimo con los cambios de `.env` que pida la prueba.
 *
 * @param  array<string, string>  $cambios  clave => valor; sustituye la linea o la anade.
 * @return array{dir: string, env: string, compose: string}
 */
function redesPaquete(array $cambios = []): array
{
    $dir = sys_get_temp_dir().'/kronoqr-redes-'.bin2hex(random_bytes(6));

    mkdir($dir.'/certs', 0o755, true);
    mkdir($dir.'/copias', 0o755, true);
    copy(Repo::file('infra/compose.prod.yaml'), $dir.'/docker-compose.yml');
    copy(Repo::file('.env.example'), $dir.'/.env.example');
    copy(Repo::file('VERSION'), $dir.'/VERSION');
    touch($dir.'/certs/tls.crt');
    touch($dir.'/certs/tls.key');
    chmod($dir.'/certs/tls.crt', 0o444);
    chmod($dir.'/certs/tls.key', 0o444);

    $valores = array_merge([
        'APP_ENV' => 'production',
        'APP_URL' => 'https://fichaje.prueba.local',
        'KIOSK_VLAN_CIDR' => '10.92.0.0/24',
        'PORTAL_INTERNAL_CIDR' => '10.90.0.0/24',
        'TLS_ALLOW_SELF_SIGNED' => 'false',
        'BACKUP_PATH' => $dir.'/copias',
    ], $cambios);

    $env = (string) file_get_contents($dir.'/.env.example');

    foreach ($valores as $clave => $valor) {
        $linea = $clave.'='.$valor;
        $env = preg_match('/^'.preg_quote($clave, '/').'=.*$/m', $env) === 1
            ? (string) preg_replace('/^'.preg_quote($clave, '/').'=.*$/m', addcslashes($linea, '\\$'), $env)
            : $env."\n".$linea."\n";
    }

    file_put_contents($dir.'/.env', $env);

    return ['dir' => $dir, 'env' => $dir.'/.env', 'compose' => $dir.'/docker-compose.yml'];
}

function redesBorrar(string $dir): void
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
 * La fase 1 de `install.sh` sobre un paquete. Salida estandar y de error juntas.
 *
 * @param  array{dir: string, env: string, compose: string}  $paquete
 * @return array{codigo: int, salida: string}
 */
function redesFase1(array $paquete, string $idioma = 'es'): array
{
    $proceso = new Process(
        ['bash', Repo::file('infra/scripts/install.sh'), '--check-only', '--compose-file', $paquete['compose']],
        env: ['KRONOQR_LANG' => $idioma],
        timeout: 120.0,
    );
    $proceso->run();

    return ['codigo' => (int) $proceso->getExitCode(), 'salida' => $proceso->getOutput().$proceso->getErrorOutput()];
}

/**
 * Una funcion de `lib/checks.sh` (o de doctor.sh) contra un `.env` de juguete.
 *
 * @param  array<string, string>  $env  El contenido del `.env`.
 * @return array{codigo: int, salida: string, fallos: int, avisos: int}
 */
function redesComprobar(string $llamada, array $env, string $script = 'lib/checks.sh'): array
{
    $fichero = sys_get_temp_dir().'/kronoqr-redes-env-'.bin2hex(random_bytes(6));
    file_put_contents($fichero, implode("\n", array_map(
        static fn (string $clave, string $valor): string => $clave.'='.$valor,
        array_keys($env),
        $env,
    ))."\n");

    $scripts = Repo::file('infra/scripts');
    $carga = $script === 'lib/checks.sh'
        ? '. '.escapeshellarg($scripts.'/lib/exit-codes.sh').'; . '.escapeshellarg($scripts.'/lib/messages.sh')
            .'; . '.escapeshellarg($scripts.'/lib/checks.sh').'; . '.escapeshellarg($scripts.'/lib/env-file.sh')
        : '. '.escapeshellarg($scripts.'/doctor.sh');

    $proceso = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; '.$carga.'; '
            .'env_value() { kq_env_value "$1" "$2"; }; '
            .'CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0; kq_msg_init es; '
            .'ENV_FILE='.escapeshellarg($fichero).'; '.$llamada.'; '
            .'echo "@@ fallos=${CHECKS_FAILED} avisos=${CHECKS_WARNED}"'
        ),
        timeout: 60.0,
    );
    $proceso->run();
    unlink($fichero);

    $salida = $proceso->getOutput().$proceso->getErrorOutput();
    preg_match('/@@ fallos=(\d+) avisos=(\d+)/', $salida, $m);

    return [
        'codigo' => (int) $proceso->getExitCode(),
        'salida' => $salida,
        'fallos' => (int) ($m[1] ?? -1),
        'avisos' => (int) ($m[2] ?? -1),
    ];
}

const EDGE_NETWORKS_CHECKS_REDES_OK = [
    'KIOSK_VLAN_CIDR' => '10.0.20.0/24',
    'PORTAL_INTERNAL_CIDR' => '10.0.0.0/16',
    'METRICS_ALLOW_CIDR' => '172.29.0.20/32',
];

it('I1: acepta el valor de serie de METRICS_ALLOW_CIDR, que es el unico que cubre a Prometheus', function (): void {
    // El instalador rechazaba cualquier valor igual al de la plantilla, y el de
    // la plantilla (172.29.0.20/32, la IP fija de Prometheus) es justo el que
    // funciona: obligaba a cambiarlo por otro que dejaba a Prometheus con 403.
    $paquete = redesPaquete();

    expect((string) file_get_contents($paquete['env']))->toMatch('/^METRICS_ALLOW_CIDR=172\.29\.0\.20\/32/m');

    $fase1 = redesFase1($paquete);

    expect($fase1['salida'])->not->toContain('METRICS_ALLOW_CIDR sigue con el valor de ejemplo')
        ->and($fase1['salida'])->not->toMatch('/\[FALLA\][^\n]*METRICS_ALLOW_CIDR/')
        ->and($fase1['salida'])->toContain('METRICS_ALLOW_CIDR es un CIDR IPv4 valido');

    redesBorrar($paquete['dir']);
})->group('RF-PD-02', 'RQ-11', 'RS-09');

it('I1: sigue rechazando que METRICS_ALLOW_CIDR este vacio o no sea un CIDR, con el motivo correcto', function (string $valor, string $texto): void {
    $paquete = redesPaquete(['METRICS_ALLOW_CIDR' => $valor]);

    $fase1 = redesFase1($paquete);

    expect($fase1['codigo'])->toBe(2)
        ->and($fase1['salida'])->toContain($texto)
        // El mensaje ya no dice que «ningun quiosco puede llegar», que era el
        // motivo equivocado de este campo.
        ->and($fase1['salida'])->not->toContain('ningun quiosco puede llegar');

    redesBorrar($paquete['dir']);
})->with([
    'vacio' => ['', 'METRICS_ALLOW_CIDR sin rellenar'],
    'sin prefijo' => ['172.29.0.20', "METRICS_ALLOW_CIDR='172.29.0.20' no es un CIDR IPv4 valido"],
    'prefijo 33' => ['172.29.0.20/33', 'no es un CIDR IPv4 valido'],
])->group('RF-PD-02', 'RQ-11');

it('PP-01: rechaza en la fase 1 un CIDR del portal o de los quioscos que nginx no arrancaria', function (string $clave, string $valor): void {
    $paquete = redesPaquete([$clave => $valor]);

    $fase1 = redesFase1($paquete);

    expect($fase1['codigo'])->toBe(2)
        ->and($fase1['salida'])->toContain("{$clave}='{$valor}' no es un CIDR IPv4 valido")
        ->and($fase1['salida'])->toContain('bucle de reinicio');

    redesBorrar($paquete['dir']);
})->with([
    'portal, dos rangos' => ['PORTAL_INTERNAL_CIDR', '10.0.0.0/8,172.16.0.0/12'],
    'portal, IPv6' => ['PORTAL_INTERNAL_CIDR', 'fd00::/8'],
    'quioscos, octeto 256' => ['KIOSK_VLAN_CIDR', '10.256.0.0/24'],
])->group('RF-PD-02', 'RF-ID-08');

it('PP-01: un portal abierto a internet es un AVISO, nunca un error', function (): void {
    // El propietario lo abre a proposito (RF-ID-08): tiene que ser visible y no
    // puede impedir instalar.
    $paquete = redesPaquete(['PORTAL_INTERNAL_CIDR' => '0.0.0.0/0']);

    $fase1 = redesFase1($paquete);

    expect($fase1['salida'])->toContain('[aviso] PORTAL_INTERNAL_CIDR=0.0.0.0/0: el portal del empleado esta abierto a internet')
        ->and($fase1['salida'])->toContain('docs/cliente/endurecimiento.md')
        ->and($fase1['salida'])->not->toMatch('/\[FALLA\][^\n]*PORTAL_INTERNAL_CIDR/');

    redesBorrar($paquete['dir']);
})->group('RF-PD-02', 'RF-ID-08');

it('PP-01: avisa de un rango publico, de uno que no cubre a nadie y del ejemplo de desarrollo', function (): void {
    $publico = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'PORTAL_INTERNAL_CIDR' => '203.0.113.0/24',
    ]);
    $nadie = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'KIOSK_VLAN_CIDR' => '10.0.20.0/24', 'PORTAL_INTERNAL_CIDR' => '192.168.250.0/24',
    ]);

    expect($publico['fallos'])->toBe(0)
        ->and($publico['salida'])->toContain('incluye direcciones que no son de una red privada')
        ->and($nadie['fallos'])->toBe(0)
        // Solo se afirma lo que se sabe: si el servidor de pruebas tiene una
        // direccion en ese rango, no avisa; si no, avisa. Nunca falla.
        ->and($nadie['codigo'])->toBe(0);
})->group('RF-PD-02', 'RF-ID-08');

it('PP-01: el rango del portal que contiene a los quioscos cuenta como cubierto', function (): void {
    $resultado = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'KIOSK_VLAN_CIDR' => '10.0.20.0/24', 'PORTAL_INTERNAL_CIDR' => '10.0.0.0/16',
    ]);

    expect($resultado['fallos'])->toBe(0)
        ->and($resultado['avisos'])->toBe(0)
        ->and($resultado['salida'])->toContain('cubre una red de este servidor o la de los quioscos');
})->group('RF-PD-02', 'RF-ID-08');

it('I1: avisa de que METRICS_ALLOW_CIDR no cubre a Prometheus solo con la observabilidad encendida', function (): void {
    $encendida = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'METRICS_ALLOW_CIDR' => '10.0.0.0/8', 'COMPOSE_PROFILES' => 'observability',
    ]);
    $apagada = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'METRICS_ALLOW_CIDR' => '10.0.0.0/8', 'COMPOSE_PROFILES' => '',
    ]);
    $cubierta = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'METRICS_ALLOW_CIDR' => '172.29.0.0/16', 'COMPOSE_PROFILES' => 'backup,observability',
    ]);

    expect($encendida['salida'])->toContain('no cubre a Prometheus (172.29.0.20)')
        ->and($encendida['fallos'])->toBe(0)
        ->and($apagada['salida'])->not->toContain('no cubre a Prometheus')
        ->and($cubierta['salida'])->toContain('cubre a Prometheus (172.29.0.20)');
})->group('RF-PD-02', 'RS-09');

it('I1: la IP de Prometheus que comparan los scripts es la fija del compose de produccion', function (): void {
    $compose = (string) file_get_contents(Repo::file('infra/compose.prod.yaml'));
    $checks = (string) file_get_contents(Repo::file('infra/scripts/lib/checks.sh'));

    preg_match('/readonly KQ_PROMETHEUS_IP="([0-9.]+)"/', $checks, $m);

    expect($m[1] ?? '')->not->toBe('')
        ->and($compose)->toContain('ipv4_address: '.($m[1] ?? 'x'));
})->group('RF-PD-02');

it('PP-03: TRUSTED_PROXY_CIDR invalida o con 0.0.0.0/0 se rechaza; una lista valida pasa', function (): void {
    $llamada = 'check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing';

    $valida = redesComprobar($llamada, [...EDGE_NETWORKS_CHECKS_REDES_OK, 'TRUSTED_PROXY_CIDR' => '10.0.0.5/32, 10.0.1.0/24']);
    $cualquiera = redesComprobar($llamada, [...EDGE_NETWORKS_CHECKS_REDES_OK, 'TRUSTED_PROXY_CIDR' => '10.0.0.5/32,0.0.0.0/0']);
    $invalida = redesComprobar($llamada, [...EDGE_NETWORKS_CHECKS_REDES_OK, 'TRUSTED_PROXY_CIDR' => '10.0.0.5']);
    $vacia = redesComprobar($llamada, [...EDGE_NETWORKS_CHECKS_REDES_OK, 'TRUSTED_PROXY_CIDR' => '']);

    expect($valida['fallos'])->toBe(0)
        ->and($valida['salida'])->toContain('nginx toma la IP real de X-Forwarded-For solo de esos proxies')
        ->and($cualquiera['fallos'])->toBe(1)
        ->and($cualquiera['salida'])->toContain('TRUSTED_PROXY_CIDR incluye 0.0.0.0/0')
        ->and($invalida['fallos'])->toBe(1)
        ->and($invalida['salida'])->toContain('no es un CIDR IPv4 valido')
        // Vacia = sin proxy: ni un comentario sobre proxies.
        ->and($vacia['fallos'])->toBe(0)
        ->and($vacia['salida'])->not->toContain('TRUSTED_PROXY_CIDR');
})->group('RF-PD-02', 'RS-02');

it('PP-01 y PP-03: update.sh y doctor.sh comprueban las mismas redes que el instalador', function (): void {
    $update = (string) file_get_contents(Repo::file('infra/scripts/update.sh'));
    $doctor = (string) file_get_contents(Repo::file('infra/scripts/doctor.sh'));

    // Si solo las mirara el instalador, un .env editado despues (o una
    // actualizacion) dejaria el borde en bucle de reinicio sin que nadie avise.
    expect($update)->toContain('check_edge_networks')
        ->and($update)->toContain('check_network_cidrs "${CURRENT_ENV}"')
        ->and($doctor)->toContain('check_network_cidrs "${CURRENT_ENV}"');

    // Y `update.sh` las comprueba ANTES de abrir el mantenimiento: es un
    // requisito (exit 2, nada tocado), no un fallo a mitad de actualizacion.
    $posicionPrecondiciones = strpos($update, 'readonly -a PRECONDITION_CHECKS=(');
    $posicionCheck = strpos($update, '  check_edge_networks', (int) $posicionPrecondiciones);

    expect($posicionPrecondiciones)->not->toBeFalse()
        ->and($posicionCheck)->not->toBeFalse()
        ->and($posicionCheck - (int) $posicionPrecondiciones)->toBeLessThan(600);
})->group('RF-PD-02', 'RF-PD-13');

it('I3: avisa, sin impedir instalar, de correo de desarrollo, alertas sin destino y licencia ausente', function (): void {
    // .env.example trae MAIL_HOST=mailpit, las seis ALERT_* vacias, LICENSE_KEY
    // vacia y COMPOSE_PROFILES=observability: el camino por defecto.
    $paquete = redesPaquete();

    $fase1 = redesFase1($paquete);

    expect($fase1['salida'])->toContain('[aviso] MAIL_HOST=mailpit: el correo saliente no esta configurado')
        ->and($fase1['salida'])->toContain('[aviso] ALERT_EMAIL_IT, ALERT_EMAIL_RRHH y ALERT_EMAIL_SEGURIDAD estan vacias')
        ->and($fase1['salida'])->toContain('[aviso] LICENSE_KEY vacia: el producto se instala sin licencia')
        // Avisos: ninguno cuenta como fallo.
        ->and($fase1['salida'])->not->toMatch('/\[FALLA\][^\n]*(MAIL_HOST|ALERT_|LICENSE_KEY)/');

    redesBorrar($paquete['dir']);
})->group('RF-PD-02', 'RF-PD-11', 'RQ-11');

it('I3: COMPLIANCE_PROFILE vacio impide instalar, y dice que hacer', function (): void {
    $paquete = redesPaquete(['COMPLIANCE_PROFILE' => '']);

    $fase1 = redesFase1($paquete);

    expect($fase1['codigo'])->toBe(2)
        ->and($fase1['salida'])->toContain('COMPLIANCE_PROFILE sin rellenar')
        ->and($fase1['salida'])->toContain('Que hacer');

    redesBorrar($paquete['dir']);
})->group('RF-PD-02', 'RQ-11');

it('I3: no avisa de lo que el cliente ha rellenado bien', function (): void {
    $resultado = redesComprobar('check_operational_settings "$ENV_FILE" /srv/kq/docker-compose.yml', [
        'COMPOSE_PROFILES' => 'observability',
        'COMPLIANCE_PROFILE' => 'ES-hosteleria',
        'LICENSE_KEY' => 'KQL1.eyJhIjoxfQ.c2lnbmF0dXJh',
        'MAIL_HOST' => 'smtp.hotel.example',
        'ALERT_EMAIL_IT' => 'it@hotel.example',
        'ALERT_EMAIL_RRHH' => 'rrhh@hotel.example',
        'ALERT_EMAIL_SEGURIDAD' => 'seguridad@hotel.example',
        'ALERT_WEBHOOK_IT' => 'https://chat.hotel.example/hooks/it',
    ]);

    expect($resultado['fallos'])->toBe(0)
        ->and($resultado['avisos'])->toBe(0);
})->group('RF-PD-02', 'RF-PD-11');

it('I3: avisa de un destinatario vacio, de un correo o un webhook mal escritos y de una licencia mal copiada', function (): void {
    $resultado = redesComprobar('check_operational_settings "$ENV_FILE" /srv/kq/docker-compose.yml', [
        'COMPOSE_PROFILES' => 'observability',
        'COMPLIANCE_PROFILE' => 'ES-hosteleria',
        'LICENSE_KEY' => 'KQL1.solo-una-parte',
        'MAIL_HOST' => 'smtp.hotel.example',
        'ALERT_EMAIL_IT' => 'it@hotel.example',
        'ALERT_EMAIL_RRHH' => '',
        'ALERT_EMAIL_SEGURIDAD' => 'seguridad sin arroba',
        'ALERT_WEBHOOK_IT' => 'chat.hotel.example/hooks/it',
    ]);

    expect($resultado['fallos'])->toBe(0)
        ->and($resultado['avisos'])->toBe(4)
        ->and($resultado['salida'])->toContain('ALERT_EMAIL_RRHH esta vacia')
        ->and($resultado['salida'])->toContain('ALERT_EMAIL_SEGURIDAD no parece una direccion de correo')
        ->and($resultado['salida'])->toContain('ALERT_WEBHOOK_IT no empieza por http')
        ->and($resultado['salida'])->toContain('LICENSE_KEY no tiene el formato');
})->group('RF-PD-02', 'RF-PD-11');

it('I3: sin observabilidad no pide destinatarios de alerta, que nadie lee', function (): void {
    $resultado = redesComprobar('check_operational_settings "$ENV_FILE" /srv/kq/docker-compose.yml', [
        'COMPOSE_PROFILES' => '',
        'COMPLIANCE_PROFILE' => 'ES-hosteleria',
        'LICENSE_KEY' => 'KQL1.a.b',
        'MAIL_HOST' => 'smtp.hotel.example',
    ]);

    expect($resultado['avisos'])->toBe(0)
        ->and($resultado['salida'])->not->toContain('ALERT_');
})->group('RF-PD-02');

it('la aritmetica de redes acierta en los bordes', function (): void {
    $casos = [
        // [llamada, codigo esperado]
        ['kq_cidr_overlaps 10.0.0.0/16 10.0.255.255', 0],
        ['kq_cidr_overlaps 10.0.0.0/16 10.1.0.0', 1],
        ['kq_cidr_overlaps 10.0.20.0/24 10.0.0.0/16', 0],
        ['kq_cidr_overlaps 172.29.0.20/32 172.29.0.20', 0],
        ['kq_cidr_overlaps 172.29.0.20/32 172.29.0.21', 1],
        ['kq_cidr_overlaps 0.0.0.0/0 255.255.255.255', 0],
        ['kq_cidr_is_private 172.16.0.0/12', 0],
        ['kq_cidr_is_private 172.16.0.0/11', 1],
        ['kq_cidr_is_private 192.168.1.0/24', 0],
        ['kq_cidr_is_private 100.64.0.0/10', 0],
        ['kq_cidr_is_private 203.0.113.0/24', 1],
        ['kq_cidr_is_private 0.0.0.0/0', 1],
        ['kq_cidr_valid 010.0.0.0/8', 1],
        ['kq_cidr_valid 10.0.0.0/33', 1],
        ['kq_cidr_valid 255.255.255.255/32', 0],
    ];

    foreach ($casos as [$llamada, $esperado]) {
        $resultado = redesComprobar('rc=0; '.$llamada.' || rc=$?; echo "@@rc=${rc}"', []);
        preg_match('/@@rc=(\d+)/', $resultado['salida'], $m);

        expect((int) ($m[1] ?? -1))->toBe($esperado, $llamada);
    }
})->group('RF-PD-02');

it('R0: doctor.sh da por fallido un Redis en bucle de reinicio y explica como repararlo', function (): void {
    $script = Repo::file('infra/scripts/doctor.sh');

    $ejecuta = function (string $info, string $registro) use ($script): array {
        $proceso = Process::fromShellCommandline(
            'bash -c '.escapeshellarg(
                'set -Eeuo pipefail; . '.escapeshellarg($script).'; kq_msg_init es; '
                .'CURRENT_COMPOSE=/srv/kq/docker-compose.yml; '
                .'compose_current() { case "$1" in ps) echo abc123;; logs) echo "${LOGS}";; esac; }; '
                .'docker() { echo "${INFO}"; }; '
                .'check_redis_restart_loop; echo "@@ fallos=${CHECKS_FAILED}"'
            ),
            env: ['INFO' => $info, 'LOGS' => $registro],
            timeout: 30.0,
        );
        $proceso->run();
        $salida = $proceso->getOutput().$proceso->getErrorOutput();
        preg_match('/@@ fallos=(\d+)/', $salida, $m);

        return ['fallos' => (int) ($m[1] ?? -1), 'salida' => $salida];
    };

    $antiguo = '2026-01-01T00:00:00.000000000Z';
    $ahora = gmdate('Y-m-d\TH:i:s').'.000000000Z';

    $aof = $ejecuta("restarting|7|{$antiguo}", '# Bad file format reading the append only file appendonly.aof.1.incr.aof');
    $otro = $ejecuta("running|5|{$ahora}", 'Out of memory');
    $estable = $ejecuta("running|5|{$antiguo}", '');
    $limpio = $ejecuta("running|0|{$antiguo}", '');

    expect($aof['fallos'])->toBe(1)
        ->and($aof['salida'])->toContain('redis-check-aof')
        ->and($aof['salida'])->toContain('/data/appendonlydir/appendonly.aof.manifest')
        ->and($aof['salida'])->toContain('stop redis')
        ->and($aof['salida'])->toContain('up -d redis')
        ->and($otro['fallos'])->toBe(1)
        ->and($otro['salida'])->toContain('no apunta al fichero AOF')
        // Reinicios antiguos de un contenedor estable: el contador no se pone a
        // cero solo, y no puede avisar para siempre.
        ->and($estable['fallos'])->toBe(0)
        ->and($limpio['fallos'])->toBe(0);
})->group('RF-PD-13');

it('R0 y DC6: doctor.sh lanza la comprobacion de Redis y la de las redes en sus dos ramas', function (): void {
    $doctor = (string) file_get_contents(Repo::file('infra/scripts/doctor.sh'));

    // Con `app` en pie se delega en product:doctor, pero estas dos son cosas
    // que product:doctor no puede ver desde dentro (el estado de reinicio de
    // otro contenedor); con `app` parada son las que quedan.
    expect(substr_count($doctor, "\n  check_redis_restart_loop\n"))->toBe(2)
        ->and(substr_count($doctor, "\n  check_edge_networks\n"))->toBe(2);
})->group('RF-PD-13');

it('los scripts no preguntan con `comando | grep -q` bajo pipefail', function (): void {
    // `grep -q` cierra el tubo al primer acierto; el productor recibe SIGPIPE, la
    // tuberia falla bajo `pipefail` y la respuesta es NO justo cuando era SI.
    // Medido: 5 de 40 invocaciones con `docker compose exec ... | grep -q`. Lo
    // que se pregunta a un comando externo se captura primero.
    $ficheros = array_merge(
        glob(Repo::file('infra/scripts').'/*.sh') ?: [],
        glob(Repo::file('infra/scripts/lib').'/*.sh') ?: [],
    );

    $infractores = [];

    foreach ($ficheros as $fichero) {
        foreach (file($fichero) ?: [] as $numero => $linea) {
            // Los comentarios explican la trampa y la muestran: no cuentan.
            if (str_starts_with(ltrim($linea), '#')) {
                continue;
            }

            if (preg_match('/\b(netstat|ss|docker|compose|compose_[a-z]+|artisan|ps|psql)\b[^|#\n]*\|\s*grep\s+-[a-zA-Z]*q/', $linea) === 1) {
                $infractores[] = basename($fichero).':'.($numero + 1).': '.trim($linea);
            }
        }
    }

    expect($infractores)->toBe([], 'Captura la salida en una variable y usa `grep -q ... <<<"${variable}"`.');
})->group('RF-PD-02', 'RQ-11');

it('los catalogos de mensajes de doctor.sh y update.sh tienen los dos idiomas completos', function (): void {
    $scripts = Repo::file('infra/scripts/lib');

    foreach (['messages-doctor.sh', 'messages-update.sh'] as $catalogo) {
        $proceso = Process::fromShellCommandline(
            'bash -c '.escapeshellarg(
                '. '.escapeshellarg($scripts.'/messages.sh').'; . '.escapeshellarg($scripts.'/'.$catalogo).'; kq_msg_init ""; kq_msg_check_catalog'
            ),
            timeout: 30.0,
        );
        $proceso->run();

        expect($proceso->getExitCode())->toBe(0, $catalogo.': '.$proceso->getErrorOutput());
    }
})->group('RF-PD-02', 'RF-PD-13');

it('PP-03: el borde recibe TRUSTED_PROXY_CIDR y la plantilla la rinde', function (): void {
    $compose = (string) file_get_contents(Repo::file('infra/compose.prod.yaml'));
    $plantilla = (string) file_get_contents(Repo::file('infra/docker/nginx/templates/kronoqr.conf.template'));

    // Dentro del bloque `environment:` de nginx, sin valor por defecto: vacia
    // no llega y el borde se comporta como siempre.
    preg_match('/\n  nginx:\n.*?\n    environment:\n(.*?)\n    ports:/s', $compose, $m);

    expect($m[1] ?? '')->toMatch('/^      TRUSTED_PROXY_CIDR:$/m')
        ->and($plantilla)->toContain('${KRONOQR_REAL_IP_DIRECTIVES}')
        // Sin la directiva escrita a mano en la plantilla: solo la variable.
        ->and($plantilla)->not->toMatch('/^\s*set_real_ip_from\s/m')
        ->and($plantilla)->not->toMatch('/^\s*real_ip_header\s/m');
})->group('RF-PD-02', 'RS-02');

it('PP-10: el borde recibe ADMIN_INTERNAL_CIDR, la rinde sin dejar el geo roto y la usan el panel y la autenticacion', function (): void {
    $compose = (string) file_get_contents(Repo::file('infra/compose.prod.yaml'));
    $plantilla = (string) file_get_contents(Repo::file('infra/docker/nginx/templates/kronoqr.conf.template'));
    $spa = (string) file_get_contents(Repo::file('infra/docker/nginx/extra/spa.conf'));
    $envExample = (string) file_get_contents(Repo::file('.env.example'));

    preg_match('/\n  nginx:\n.*?\n    environment:\n(.*?)\n    ports:/s', $compose, $m);

    expect($m[1] ?? '')->toMatch('/^      ADMIN_INTERNAL_CIDR:$/m')
        // El geo lee la variable YA RENDIDA (0.0.0.0/0 si esta vacia), nunca la cruda:
        // vacia daria una entrada `geo` rota y nginx no arrancaria.
        ->and($plantilla)->toContain('${KRONOQR_ADMIN_ALLOWED_CIDR} 1;')
        ->and($plantilla)->not->toContain('${ADMIN_INTERNAL_CIDR}')
        ->and($plantilla)->toMatch('/location \^~ \/api\/v1\/auth\/ \{\s+if \(\$kronoqr_admin_allowed = 0\) \{\s+return 403;/')
        ->and($spa)->toMatch('/location \^~ \/admin\/ \{[^}]*\$kronoqr_admin_allowed = 0/s')
        // Por defecto abierta: vacia en .env.example.
        ->and($envExample)->toMatch('/^ADMIN_INTERNAL_CIDR=$/m');

    $envsh = (string) file_get_contents(Repo::file('infra/docker/nginx/docker-entrypoint.d/07-kronoqr-admin-net.envsh'));

    expect($envsh)->toContain('KRONOQR_ADMIN_ALLOWED_CIDR="${ADMIN_INTERNAL_CIDR:-0.0.0.0/0}"');
})->group('RS-02', 'RF-PD-02');

it('PP-09: el aviso de un portal abierto o publico recomienda el PIN de 8 digitos, en los dos idiomas', function (string $cidr): void {
    $es = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'PORTAL_INTERNAL_CIDR' => $cidr,
    ]);

    // Aviso, jamas un fallo: el propietario abre el portal a proposito.
    expect($es['fallos'])->toBe(0)
        ->and($es['avisos'])->toBeGreaterThan(0)
        ->and($es['salida'])->toContain('PIN de 8 digitos')
        ->and($es['salida'])->toContain('ADR-050');

    $catalogo = (string) file_get_contents(Repo::file('infra/scripts/lib/messages.sh'));

    expect($catalogo)->toContain('turn on the 8-digit PIN');
})->with(['abierto' => '0.0.0.0/0', 'publico' => '203.0.113.0/24'])->group('RF-PD-02', 'RF-ID-08', 'RS-12');

it('PP-09: un rango privado que no incluye este servidor no recomienda el PIN: es otro aviso', function (): void {
    $resultado = redesComprobar('check_network_cidrs "$ENV_FILE" /srv/kq/docker-compose.yml skip-missing', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'KIOSK_VLAN_CIDR' => '10.0.20.0/24', 'PORTAL_INTERNAL_CIDR' => '10.0.0.0/16',
    ]);

    expect($resultado['salida'])->not->toContain('PIN de 8 digitos');
})->group('RF-PD-02', 'RF-ID-08');

it('PP-09: kq_portal_exposed dice true solo con el portal abierto o con direcciones publicas', function (string $cidr, string $esperado): void {
    $resultado = redesComprobar('printf "[%s]" "$(kq_portal_exposed "$ENV_FILE")"', [
        ...EDGE_NETWORKS_CHECKS_REDES_OK, 'PORTAL_INTERNAL_CIDR' => $cidr,
    ]);

    expect($resultado['salida'])->toContain('['.$esperado.']');
})->with([
    'abierto' => ['0.0.0.0/0', 'true'],
    'publico' => ['203.0.113.0/24', 'true'],
    'se sale de 172.16/12' => ['172.16.0.0/11', 'true'],
    'rfc1918 10/8' => ['10.0.0.0/16', ''],
    'rfc1918 192.168/16' => ['192.168.1.0/24', ''],
    'bucle local' => ['127.0.0.0/8', ''],
    'invalido' => ['no-es-un-cidr', ''],
    'vacio' => ['', ''],
])->group('RF-PD-02', 'RF-ID-08');

it('PP-09: update.sh anota portal_exposed como booleano true en el asiento y solo cuando procede', function (): void {
    $proceso = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
            .'printf "%s\n%s" "$(audit_json_object "to_version=2.2.0" "portal_exposed=true")" '
            .'"$(audit_json_object "to_version=2.2.0" "portal_exposed=")"'
        ),
        timeout: 60.0,
    );
    $proceso->run();

    $lineas = explode("\n", trim($proceso->getOutput()));

    expect($lineas[0])->toBe('{"to_version":"2.2.0","portal_exposed":true}')
        ->and($lineas[1] ?? '')->toBe('{"to_version":"2.2.0"}');
})->group('RF-PD-10', 'RF-ID-08');
