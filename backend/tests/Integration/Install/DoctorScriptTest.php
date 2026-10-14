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

it('doctor.sh lanza la comprobacion de las reglas de Prometheus en sus dos ramas', function (): void {
    expect(substr_count(Repo::contents('infra/scripts/doctor.sh'), "\n  check_prometheus_rules\n"))->toBe(2);
})->group('RF-PD-13');
