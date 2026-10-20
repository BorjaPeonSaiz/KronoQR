<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * `doctor.sh` desde fuera del contenedor, EJECUTADO (RF-PD-13).
 *
 * Lo que necesita Docker se sustituye por una funcion: `doctor.sh` lleva la
 * misma guarda que `install.sh` y `update.sh`, asi que se carga sin ejecutarlo y
 * `compose_current` responde lo que se le diga. La comprobacion completa, con
 * contenedores de verdad, es la de docker-in-docker de la verificacion final.
 *
 * DE DONDE SALE (V3-PL-07). Con `app` en marcha, `doctor.sh` delegaba en
 * `product:doctor` —que corre DENTRO de `app` y no ve los demas contenedores— y
 * salia con 0 y «Nada esta roto» con el planificador parado: sin copias, sin
 * verificacion de la cadena y sin conciliacion, y sin que nada lo dijera.
 */

/**
 * Carga doctor.sh y ejecuta `check_background_services` con `docker compose ps`
 * respondiendo $estados (servicio => estado) .
 *
 * @param  array<string, string>  $estados
 */
function doctorConServicios(array $estados): Process
{
    $lineas = '';
    foreach ($estados as $servicio => $estado) {
        $lineas .= $servicio.' '.$estado.'\n';
    }

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr-2.2.0/docker-compose.yml; '
        .'compose_current() { printf '.escapeshellarg($lineas).'; }; '
        .'check_background_services; '
        .'printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();

    return $process;
}

it('da por buena la instalacion con los tres procesos de fondo en marcha', function (): void {
    $proceso = doctorConServicios(['app' => 'running', 'scheduler' => 'running', 'horizon' => 'running', 'reverb' => 'running']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=0')
        ->and($proceso->getOutput())->toContain('[ok]    Servicio scheduler: running');
})->group('RF-PD-13');

it('falla con el planificador parado y dice que no hay copias y como arrancarlo (V3-PL-07)', function (): void {
    $proceso = doctorConServicios(['app' => 'running', 'scheduler' => 'exited', 'horizon' => 'running', 'reverb' => 'running']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=1 avisos=0')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio scheduler: exited')
        ->and($proceso->getOutput())->toContain('copia nocturna')
        ->and($proceso->getOutput())->toContain('sudo docker compose -f /opt/kronoqr-2.2.0/docker-compose.yml up -d scheduler');
})->group('RF-PD-13');

it('falla con horizon parado o ausente y solo avisa con reverb parado (V3-PL-07)', function (): void {
    $proceso = doctorConServicios(['app' => 'running', 'scheduler' => 'running', 'reverb' => 'exited']);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=1 avisos=1')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio horizon: (ausente)')
        ->and($proceso->getOutput())->toContain('up -d horizon')
        ->and($proceso->getOutput())->toContain('[aviso] Servicio reverb: exited')
        ->and($proceso->getOutput())->toContain('up -d reverb');
})->group('RF-PD-13');

it('dice lo mismo en ingles', function (): void {
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init en; CURRENT_COMPOSE=/opt/k/docker-compose.yml; '
        .'compose_current() { printf "app running\nscheduler exited\nhorizon exited\nreverb exited\n"; }; '
        .'check_background_services';
    $proceso = new Process(['bash', '-c', $script], timeout: 30.0);
    $proceso->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('nightly backup')
        ->and($proceso->getOutput())->toContain('What to do')
        ->and($proceso->getOutput())->not->toContain('Que hacer')
        ->and($proceso->getOutput())->not->toContain('copia');
})->group('RF-PD-13');

it('avisa si se ejecuta desde un directorio que no es el de la instalacion vigente (V7-SC-1)', function (): void {
    // Tras una actualizacion lado a lado el directorio anterior sigue ahi; quien
    // lanza doctor.sh desde el es quien despues lanzara `docker compose` desde el.
    $base = sys_get_temp_dir().'/kronoqr-doctor-'.bin2hex(random_bytes(6));
    mkdir($base.'/viejo/lib', 0o755, true);
    mkdir($base.'/nuevo', 0o755, true);
    foreach (['doctor.sh'] as $fichero) {
        copy(Repo::file('infra/scripts/'.$fichero), $base.'/viejo/'.$fichero);
    }
    foreach (glob(Repo::file('infra/scripts/lib').'/*.sh') ?: [] as $lib) {
        copy($lib, $base.'/viejo/lib/'.basename($lib));
    }
    file_put_contents($base.'/viejo/VERSION', "2.1.0\n");
    file_put_contents($base.'/nuevo/.env', "IMAGE_TAG=2.2.0\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg($base.'/viejo/doctor.sh').'; '
        .'kq_msg_init es; CURRENT_DIR='.escapeshellarg($base.'/nuevo').'; CURRENT_ENV="${CURRENT_DIR}/.env"; '
        .'check_running_from_current_dir; printf "avisos=%s\n" "${CHECKS_WARNED}"; '
        .'CURRENT_DIR='.escapeshellarg($base.'/viejo').'; CHECKS_WARNED=0; '
        .'check_running_from_current_dir; printf "avisos=%s\n" "${CHECKS_WARNED}"';
    $proceso = new Process(['bash', '-c', $script], timeout: 30.0);
    $proceso->run();

    $salida = $proceso->getOutput();
    (new Process(['rm', '-rf', $base]))->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($salida)->toContain('(version 2.1.0), que no es el de la instalacion vigente')
        ->and($salida)->toContain('(version 2.2.0)')
        ->and($salida)->toContain("avisos=1\n")
        ->and($salida)->toContain("avisos=0\n");
})->group('RF-PD-13');

it('con la aplicacion parada juzga los procesos de fondo con la misma gravedad que con ella en marcha', function (): void {
    // check_services_state (rama de `app` parada) solo avisaba de todo; ahora
    // scheduler y horizon parados son fallo tambien ahi, y si su contenedor ni
    // existe se dice.
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr-2.2.0/docker-compose.yml; '
        .'compose_current() { printf "app exited\nnginx exited\nscheduler exited\nreverb running\n"; }; '
        .'check_services_state; printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';
    $proceso = new Process(['bash', '-c', $script], timeout: 30.0);
    $proceso->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        // scheduler y horizon (ausente): fallo; app y nginx: aviso, como antes.
        ->and($proceso->getOutput())->toContain('fallos=2 avisos=2')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio scheduler: exited')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio horizon: (ausente)')
        ->and($proceso->getOutput())->toContain('[aviso] Servicio nginx: exited')
        ->and($proceso->getOutput())->toContain('[ok]    Servicio reverb: running');
})->group('RF-PD-13');

it('las bases huerfanas se buscan ancladas a «<base>_restore_<fecha>» y no casan con las de la vuelta atras (<base>_pre_restore_)', function (): void {
    $doctor = Repo::contents('infra/scripts/doctor.sh');

    expect($doctor)->toContain("datname ~ '^\${db}_restore_[0-9]{8}T[0-9]{6}Z")
        ->and($doctor)->not->toContain("datname ~ '_restore_[0-9]");
})->group('RNF-D-04', 'RF-PD-13');

it('avisa si Prometheus no tiene cargadas las reglas de alerta del paquete (pendiente del bloque 13)', function (): void {
    $base = sys_get_temp_dir().'/kronoqr-doctor-reglas-'.bin2hex(random_bytes(6));
    mkdir($base.'/observability/prometheus/rules', 0o755, true);
    file_put_contents(
        $base.'/observability/prometheus/rules/a.yml',
        "groups:\n  - name: g\n    rules:\n      - alert: ReglaUno\n        expr: up == 0\n      - alert: ReglaDos\n        expr: up == 0\n"
        ."      # - alert: Comentada\n      - record: no_es_una_alerta\n        expr: up\n",
    );
    file_put_contents(
        $base.'/observability/prometheus/rules/b.yml',
        "groups:\n  - name: h\n    rules:\n      - alert: ReglaTres\n        expr: up == 0\n",
    );

    $json = static fn (array $nombres): string => '{"status":"success","data":{"groups":[{"name":"g","file":"/x","rules":['
        .implode(',', array_map(static fn (string $n): string => '{"state":"inactive","name":"'.$n.'","query":"up == 0","type":"alerting"}', $nombres))
        .']}]}}';

    $ejecuta = function (string $contenedor, string $respuesta) use ($base): array {
        $proceso = Process::fromShellCommandline(
            'bash -c '.escapeshellarg(
                'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; kq_msg_init es; '
                .'CURRENT_DIR='.escapeshellarg($base).'; CURRENT_COMPOSE=/srv/kq/docker-compose.yml; '
                .'compose_current() { case "$1" in ps) printf "%s" "${CONTENEDOR}";; exec) printf "%s" "${RESPUESTA}";; esac; }; '
                .'check_prometheus_rules; echo "@@ comprobadas=${CHECKS_RUN} avisos=${CHECKS_WARNED} fallos=${CHECKS_FAILED}"'
            ),
            env: ['CONTENEDOR' => $contenedor, 'RESPUESTA' => $respuesta],
            timeout: 30.0,
        );
        $proceso->run();
        expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput());

        return ['salida' => $proceso->getOutput().$proceso->getErrorOutput()];
    };

    $completo = $ejecuta('abc123', $json(['ReglaUno', 'ReglaDos', 'ReglaTres', 'AjenaDelCliente']));
    $falta = $ejecuta('abc123', $json(['ReglaUno']));
    $muda = $ejecuta('abc123', '');
    $sinObservabilidad = $ejecuta('', '');
    (new Process(['rm', '-rf', $base]))->run();

    expect($completo['salida'])->toContain('3 reglas de alerta cargadas')->toContain('avisos=0')
        // Una regla de un registro (`record:`) o comentada no es una alerta que esperar.
        ->and($falta['salida'])->toContain('no tiene cargadas 2 regla(s)')
        ->and($falta['salida'])->toContain('ReglaDos')->toContain('ReglaTres')
        ->and($falta['salida'])->toContain('--force-recreate prometheus')->toContain('avisos=1')->toContain('fallos=0')
        ->and($muda['salida'])->toContain('no contesta a la lista de reglas')->toContain('avisos=1')
        // Sin el perfil `observability` no hay Prometheus y no hay nada que comprobar.
        ->and($sinObservabilidad['salida'])->toContain('comprobadas=0')->toContain('avisos=0');
})->group('RF-PD-13');

/**
 * Carga doctor.sh y ejecuta `check_maintenance_mode` con `curl` respondiendo
 * $codigo a la API de gestion por el borde.
 */
function doctorConLaApiRespondiendo(string $codigo): string
{
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_ENV=/dev/null; CURRENT_COMPOSE=/opt/kronoqr-2.3.0/docker-compose.yml; '
        .'env_value() { [ "$2" = HTTPS_PORT ] && printf 8443 || true; }; '
        .'curl() { printf "%s" '.escapeshellarg($codigo).'; }; '
        .'check_maintenance_mode; '
        .'printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $process->getOutput();
}

it('da [FALLA] con la aplicacion en mantenimiento y dice como retirarlo (V21-3-PL-02)', function (): void {
    // Una vuelta atras de update.sh que sale con 5 y a la que a mano se le salta el
    // `artisan up` deja la API en 503: el quiosco encola, y el panel, el portal y la
    // exportacion legal no responden. Hasta la 2.3.0 doctor.sh salia con 0.
    $mantenimiento = doctorConLaApiRespondiendo('503');
    $atiende = doctorConLaApiRespondiendo('401');
    $sinRespuesta = doctorConLaApiRespondiendo('000');

    expect($mantenimiento)->toContain('[FALLA] API de gestion por el borde (https://127.0.0.1:8443/api/v1/auth/me): responde 503')
        ->and($mantenimiento)->toContain('docker compose -f /opt/kronoqr-2.3.0/docker-compose.yml exec -T app php artisan up')
        ->and($mantenimiento)->toContain('fallos=1 avisos=0')
        ->and($atiende)->toContain('fallos=0 avisos=0')
        ->and($sinRespuesta)->toContain('fallos=0 avisos=1')
        ->and($sinRespuesta)->toContain('ps nginx');
})->group('RF-PD-13', 'RF-PD-10');

it('doctor.sh comprueba el mantenimiento por el borde con la aplicacion en marcha', function (): void {
    expect(substr_count(Repo::contents('infra/scripts/doctor.sh'), "\n  check_maintenance_mode\n"))->toBe(1);
})->group('RF-PD-13');

it('doctor.sh lanza la comprobacion de las reglas de Prometheus en sus dos ramas', function (): void {
    expect(substr_count(Repo::contents('infra/scripts/doctor.sh'), "\n  check_prometheus_rules\n"))->toBe(2);
})->group('RF-PD-13');

/**
 * Carga doctor.sh y ejecuta `check_work_record_privileges` con PostgreSQL
 * respondiendo que las migraciones de la 2.2.1 estan aplicadas y `$deriva` a la
 * consulta de privilegios.
 */
function doctorConDerivaDelRegistro(string $deriva): Process
{
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; env_value() { printf fichaje_app; }; DERIVA='.escapeshellarg($deriva).'; '
        .'compose_current() { local sql; sql="$(cat)"; case "${sql}" in *"count(*) FILTER"*) printf "2,1" ;; *) printf "%s" "${DERIVA}" ;; esac; }; '
        .'check_work_record_privileges; '
        .'printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();

    return $process;
}

it('doctor.sh separa la manipulacion de las guardas de la deriva que update.sh repara, con la misma funcion que update.sh', function (string $deriva, string $texto): void {
    $proceso = doctorConDerivaDelRegistro($deriva);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=1')
        ->and($proceso->getOutput())->toContain($texto);
})->with([
    // Manipulacion: no se repara, se investiga (bloque 2 de la 2.3.0).
    'funcion de purga borrada' => ['work_record_purge_batch:AUSENTE', 'update.sh NO lo repara'],
    'funcion de la zona borrada' => ['site_correct_timezone:AUSENTE', 'update.sh NO lo repara'],
    'traza de la zona de otro propietario' => ['site_timezone_changes:PROPIETARIO', 'update.sh NO lo repara'],
    'linea base de la zona borrada' => ['site_timezone_audit_baseline:AUSENTE', 'update.sh NO lo repara'],
    'traza de la zona borrable' => ['site_timezone_changes:DELETE', 'update.sh NO lo repara'],
    // Deriva: la traza escribible por la aplicacion la repara update.sh.
    'traza de la zona escribible' => ['site_timezone_changes:INSERT', 'Ejecuta sudo ./update.sh (los repara)'],
    'trigger de la zona desactivado' => ['trigger:zz_sites_guard_timezone:DESACTIVADO', 'update.sh NO lo repara'],
    // Deriva: la repara update.sh.
    'privilegio de mas' => ['scan_events:UPDATE', 'Ejecuta sudo ./update.sh (los repara)'],
])->group('RF-PD-13', 'RS-07', 'RN-23');

it('doctor.sh da por bueno el registro sin deriva', function (): void {
    $proceso = doctorConDerivaDelRegistro('ok');

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=0');
})->group('RF-PD-13', 'RS-07');

/**
 * Carga doctor.sh y ejecuta `check_redis_restart_loop` con el contenedor de
 * Redis en el estado $estado (y $reinicios reinicios).
 */
function doctorConRedis(string $estado, int $reinicios = 0): string
{
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr-2.3.0/docker-compose.yml; '
        .'compose_current() { case "$*" in *redis*) printf "c0ffee\n" ;; esac; return 0; }; '
        .'docker() { printf "%s|%s|2026-10-20T08:00:00.000Z" '.escapeshellarg($estado).' '.$reinicios.'; }; '
        .'check_redis_restart_loop; printf "fallos=%s\n" "${CHECKS_FAILED}"';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $process->getOutput();
}

it('da [FALLA] con Redis parado, no «[ok] Redis: exited» (R3-PL-05)', function (string $estado): void {
    $salida = doctorConRedis($estado);

    expect($salida)->toContain('[FALLA] Redis esta parado ('.$estado.')')
        ->and($salida)->toContain('up -d redis')
        ->and($salida)->toContain('fallos=1')
        ->and($salida)->not->toContain('[ok]');
})->with(['exited', 'dead', 'created'])->group('RF-PD-13');

it('con la aplicacion en marcha recuerda tambien los digests anulados y explica de quien es el codigo impreso (V1-PL-03, I13)', function (): void {
    $doctor = Repo::contents('infra/scripts/doctor.sh');
    $delegado = substr($doctor, (int) strpos($doctor, "\nrun_delegated_doctor() {"), 4000);

    expect($delegado)->toContain('check_image_digest_overrides "${CURRENT_ENV}"')
        ->and($delegado)->toContain('d_doctor_code_note')
        // Y en las dos ramas: con la aplicacion parada ya estaba.
        ->and(substr_count($doctor, 'check_image_digest_overrides "${CURRENT_ENV}"'))->toBe(2);
})->group('RF-PD-13');

it('falla con el procesador de trabajos en marcha pero «unhealthy», y la orden es reiniciarlo (O1, 2.3.0)', function (): void {
    // `up -d` no arregla un contenedor vivo cuyo `healthcheck` falla: `horizon:status` dice que
    // ningun maestro esta registrado o que esta en pausa. La orden correcta es `restart`.
    $proceso = doctorConServicios([
        'app' => 'running', 'scheduler' => 'running', 'horizon' => 'running unhealthy', 'reverb' => 'running',
    ]);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=1 avisos=0')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio horizon: running (unhealthy)')
        ->and($proceso->getOutput())->toContain('sudo docker compose -f /opt/kronoqr-2.2.0/docker-compose.yml restart horizon')
        ->and($proceso->getOutput())->toContain('[ok]    Servicio scheduler: running');
})->group('RF-PD-13', 'RNF-D-01');

it('falla con el planificador «unhealthy» y solo avisa con Reverb «unhealthy» (O1, 2.3.0)', function (): void {
    $proceso = doctorConServicios([
        'app' => 'running', 'scheduler' => 'running unhealthy', 'horizon' => 'running healthy', 'reverb' => 'running unhealthy',
    ]);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=1 avisos=1')
        ->and($proceso->getOutput())->toContain('[FALLA] Servicio scheduler: running (unhealthy)')
        ->and($proceso->getOutput())->toContain('[aviso] Servicio reverb: running (unhealthy)')
        ->and($proceso->getOutput())->toContain('[ok]    Servicio horizon: running');
})->group('RF-PD-13', 'RNF-D-01');

it('no tiene por averia la guarda de retencion de Loki, que es de un solo uso y se queda «exited» (V21-4-DV-4)', function (): void {
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr-2.2.0/docker-compose.yml; '
        .'compose_current() { printf "app exited\nscheduler running\nhorizon running\nreverb running\nretention-guard exited\n"; }; '
        .'check_services_state; printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';
    $proceso = new Process(['bash', '-c', $script], timeout: 30.0);
    $proceso->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('[ok]    Servicio retention-guard: exited')
        // Solo `app` parada es aviso: la guarda no suma ninguno.
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=1');
})->group('RF-PD-13', 'RNF-D-01');

/**
 * `check_alertmanager` con un `compose_current` falso que contesta a las cuatro
 * preguntas que hace: quien esta en marcha, `/-/ready`, los Alertmanager que ve
 * Prometheus y el entorno del contenedor.
 *
 * @param  array<string, string>  $envDelContenedor  variables del contenedor en marcha
 */
function doctorConAlertmanager(string $envDelFichero, array $envDelContenedor, bool $listo = true, bool $prometheusLoVe = true, bool $conPrometheus = true): Process
{
    $directorio = sys_get_temp_dir().'/kq-doctor-am-'.bin2hex(random_bytes(4));
    mkdir($directorio, 0777, true);
    file_put_contents($directorio.'/.env', $envDelFichero);

    $entorno = '';
    foreach ($envDelContenedor as $clave => $valor) {
        $entorno .= $clave.'='.$valor.'\n';
    }
    $respuestaPrometheus = $prometheusLoVe
        ? '{"status":"success","data":{"activeAlertmanagers":[{"url":"http://alertmanager:9093/api/v2/alerts"}],"droppedAlertmanagers":[]}}'
        : '{"status":"success","data":{"activeAlertmanagers":[],"droppedAlertmanagers":[]}}';

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr/docker-compose.yml; CURRENT_ENV='.escapeshellarg($directorio.'/.env').'; '
        .'compose_current() { local IFS=" "; case "$*" in '
        .'"ps -q alertmanager") echo am123 ;; '
        .'"ps -q prometheus") '.($conPrometheus ? 'echo pr456' : 'true').' ;; '
        .'"exec -T alertmanager wget -qO- http://127.0.0.1:9093/-/ready") return '.($listo ? '0' : '1').' ;; '
        .'"exec -T prometheus wget -qO- http://127.0.0.1:9090/api/v1/alertmanagers") printf %s '.escapeshellarg($respuestaPrometheus).' ;; '
        .'"exec -T alertmanager env") printf '.escapeshellarg($entorno).' ;; '
        .'*) return 0 ;; esac; }; '
        .'check_alertmanager; printf "fallos=%s avisos=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}"';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();

    return $process;
}

it('da por bueno Alertmanager en marcha, visto por Prometheus y con los destinatarios del .env (V4-DV-4)', function (): void {
    $proceso = doctorConAlertmanager(
        "ALERT_EMAIL_IT=it@hotel.test\nALERT_WEBHOOK_IT=https://hooks.test/x?k=a=b\nMAIL_HOST=smtp.hotel.test\n",
        ['ALERT_EMAIL_IT' => 'it@hotel.test', 'ALERT_WEBHOOK_IT' => 'https://hooks.test/x?k=a=b', 'MAIL_HOST' => 'smtp.hotel.test'],
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('[ok]    Alertmanager: en marcha, Prometheus lo ve')
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=0');
})->group('RF-PD-13', 'RNF-D-01');

it('avisa si el .env cambio los destinatarios y Alertmanager sigue con los anteriores, sin mostrar valores (V4-DV-4)', function (): void {
    $proceso = doctorConAlertmanager(
        "ALERT_EMAIL_IT=nuevo@hotel.test\nMAIL_HOST=smtp.hotel.test\n",
        ['ALERT_EMAIL_IT' => 'viejo@hotel.test', 'MAIL_HOST' => 'smtp.hotel.test'],
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('[aviso] Alertmanager lleva destinatarios o ajustes distintos de los del .env')
        ->and($proceso->getOutput())->toContain('difieren: ALERT_EMAIL_IT.')
        ->and($proceso->getOutput())->toContain('up -d --force-recreate alertmanager')
        ->and($proceso->getOutput())->not->toContain('nuevo@hotel.test')
        ->and($proceso->getOutput())->not->toContain('viejo@hotel.test')
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=1');
})->group('RF-PD-13', 'RNF-D-01');

it('avisa si Alertmanager no contesta en su puerto interno (V4-DV-4)', function (): void {
    $proceso = doctorConAlertmanager('', [], listo: false);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('[aviso] Alertmanager no contesta')
        ->and($proceso->getOutput())->toContain('se evaluan y NO se entregan');
})->group('RF-PD-13', 'RNF-D-01');

it('avisa si Prometheus no ve a Alertmanager como destino activo (V4-DV-4)', function (): void {
    $proceso = doctorConAlertmanager('', [], prometheusLoVe: false);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('[aviso] Prometheus no ve a Alertmanager')
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=1');
})->group('RF-PD-13', 'RNF-D-01');

it('no dice nada si la observabilidad esta apagada y Alertmanager no existe (V4-DV-4)', function (): void {
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/doctor.sh')).'; '
        .'kq_msg_init es; CURRENT_COMPOSE=/opt/kronoqr/docker-compose.yml; '
        .'compose_current() { return 0; }; '
        .'check_alertmanager; printf "fallos=%s avisos=%s comprobaciones=%s\n" "${CHECKS_FAILED}" "${CHECKS_WARNED}" "${CHECKS_RUN}"';
    $proceso = new Process(['bash', '-c', $script], timeout: 30.0);
    $proceso->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('fallos=0 avisos=0 comprobaciones=0');
})->group('RF-PD-13', 'RNF-D-01');
