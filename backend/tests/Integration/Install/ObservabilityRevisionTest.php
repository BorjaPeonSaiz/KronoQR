<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La huella de `observability/` que hace que `up -d` recree Prometheus,
 * Alertmanager y Blackbox cuando cambian sus reglas o su configuracion (bloque 9
 * de la 2.3.0, V4-DV-4).
 *
 * El hallazgo: tras una actualizacion `in place` (o con el mismo directorio) la
 * definicion de esos servicios no cambia nada, y `up -d` los dejaba corriendo con
 * las reglas y los destinatarios de la version anterior, sin aviso. Se ejecuta
 * `set_observability_revision` de update.sh de verdad, cargado con `source`, y se
 * comprueba el contrato con el compose: la etiqueta de los tres servicios lee la
 * variable que esta funcion escribe.
 */

/**
 * @param  array<string, string>  $files  contenido de observability/ del paquete
 * @return array{0: string, 1: Process}
 */
function observabilityRevisionScenario(array $files, string $envInitial, string $after = ''): array
{
    $root = sys_get_temp_dir().'/kq-obs-revision-'.bin2hex(random_bytes(4));
    mkdir($root.'/observability', 0777, true);
    foreach ($files as $path => $content) {
        if (! is_dir(dirname($root.'/observability/'.$path))) {
            mkdir(dirname($root.'/observability/'.$path), 0777, true);
        }
        file_put_contents($root.'/observability/'.$path, $content);
    }
    file_put_contents($root.'/.env', $envInitial);
    chmod($root.'/.env', 0600);

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; PACKAGE_DIR='.escapeshellarg($root).'; ENV_FILE='.escapeshellarg($root.'/.env').'; '
        .'COMPOSE_FILE='.escapeshellarg($root.'/docker-compose.yml').'; '
        .'set_observability_revision; '.$after;

    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    return [$root, $process];
}

function observabilityRevisionOf(string $root): string
{
    preg_match('/^KQ_OBSERVABILITY_REVISION=([0-9a-f]+)$/m', (string) file_get_contents($root.'/.env'), $match);

    return $match[1] ?? '';
}

it('escribe en el .env la huella de observability/, de 16 hex, sin tocar el resto ni los permisos', function (): void {
    [$root, $process] = observabilityRevisionScenario(
        ['prometheus/rules/kiosk.yml' => "a\n", 'alertmanager/alertmanager.yml.template' => "b\n"],
        "IMAGE_TAG=2.3.0\nALERT_EMAIL_IT=it@hotel.test\n",
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(observabilityRevisionOf($root))->toMatch('/^[0-9a-f]{16}$/')
        ->and(file_get_contents($root.'/.env'))->toContain("IMAGE_TAG=2.3.0\nALERT_EMAIL_IT=it@hotel.test\n")
        ->and(decoct(fileperms($root.'/.env') & 0o777))->toBe('600');
})->group('RNF-D-01', 'RF-PD-10');

it('da la misma huella al mismo contenido y otra distinta en cuanto cambia una regla o la configuracion', function (): void {
    $base = ['prometheus/rules/kiosk.yml' => "a\n", 'alertmanager/alertmanager.yml.template' => "b\n"];

    [$uno] = observabilityRevisionScenario($base, "IMAGE_TAG=2.3.0\n");
    [$igual] = observabilityRevisionScenario($base, "IMAGE_TAG=2.3.0\n");
    [$regla] = observabilityRevisionScenario(['prometheus/rules/kiosk.yml' => "a2\n"] + $base, "IMAGE_TAG=2.3.0\n");
    [$nuevo] = observabilityRevisionScenario($base + ['prometheus/rules/queue.yml' => "c\n"], "IMAGE_TAG=2.3.0\n");

    expect(observabilityRevisionOf($uno))->not->toBe('')
        ->and(observabilityRevisionOf($igual))->toBe(observabilityRevisionOf($uno))
        ->and(observabilityRevisionOf($regla))->not->toBe(observabilityRevisionOf($uno))
        ->and(observabilityRevisionOf($nuevo))->not->toBe(observabilityRevisionOf($uno));
})->group('RNF-D-01', 'RF-PD-10');

it('sustituye la huella anterior en vez de anadir otra linea', function (): void {
    [$root, $process] = observabilityRevisionScenario(
        ['prometheus/rules/kiosk.yml' => "a\n"],
        "IMAGE_TAG=2.3.0\nKQ_OBSERVABILITY_REVISION=0000000000000000\n",
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(substr_count((string) file_get_contents($root.'/.env'), 'KQ_OBSERVABILITY_REVISION='))->toBe(1)
        ->and(observabilityRevisionOf($root))->not->toBe('0000000000000000');
})->group('RNF-D-01', 'RF-PD-10');

it('no hace nada si el paquete no trae observability/ y nunca deshace la actualizacion', function (): void {
    $root = sys_get_temp_dir().'/kq-obs-revision-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    file_put_contents($root.'/.env', "IMAGE_TAG=2.3.0\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; PACKAGE_DIR='.escapeshellarg($root).'; ENV_FILE='.escapeshellarg($root.'/.env').'; '
        .'COMPOSE_FILE='.escapeshellarg($root.'/docker-compose.yml').'; set_observability_revision; echo seguimos';
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('seguimos')
        ->and(file_get_contents($root.'/.env'))->toBe("IMAGE_TAG=2.3.0\n");
})->group('RNF-D-01');

it('avisa con la orden para recrear a mano si no puede escribir el .env, y la actualizacion sigue', function (): void {
    [$root] = observabilityRevisionScenario(['prometheus/rules/kiosk.yml' => "a\n"], "IMAGE_TAG=2.3.0\n");

    // Un .env que no se puede escribir: el directorio no existe.
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; PACKAGE_DIR='.escapeshellarg($root).'; ENV_FILE=/proc/kq-no-existe/.env; '
        .'COMPOSE_FILE=/opt/kronoqr/docker-compose.yml; set_observability_revision; echo seguimos';
    $fallo = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 60.0);
    $fallo->run();

    expect($fallo->getExitCode())->toBe(0, $fallo->getErrorOutput())
        ->and($fallo->getOutput())->toContain('seguimos')
        ->and($fallo->getOutput())->toContain('--force-recreate prometheus alertmanager blackbox-exporter');
})->group('RNF-D-01');

it('el compose etiqueta Prometheus, Alertmanager y Blackbox con la variable que escribe update.sh', function (): void {
    $compose = Repo::contents('infra/compose.prod.yaml');

    // Una por servicio: si alguno la pierde, una actualizacion in place lo deja con las reglas viejas.
    expect(substr_count($compose, 'kronoqr.observability.revision: "${KQ_OBSERVABILITY_REVISION:-}"'))->toBe(3);

    foreach (['prometheus', 'alertmanager', 'blackbox-exporter'] as $servicio) {
        $desde = (string) strstr($compose, "\n  {$servicio}:\n");
        $resto = substr($desde, 1);
        $siguiente = preg_match('/\n  [a-z-]+:\n/', $resto, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : strlen($resto);

        expect(str_contains(substr($resto, 0, $siguiente), 'kronoqr.observability.revision'))
            ->toBeTrue("{$servicio} no lleva la etiqueta de la revision.");
    }

    // La escribe en las dos ramas de prepare_package: in place y lado a lado.
    $update = Repo::contents('infra/scripts/update.sh');
    expect(substr_count($update, "\n    set_observability_revision\n"))->toBe(1)
        ->and(substr_count($update, "\n  set_observability_revision\n"))->toBe(1);
})->group('RNF-D-01', 'RF-PD-10');
