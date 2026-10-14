<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Los cambios del cliente en `observability/` sobreviven a una actualizacion
 * lado a lado (RNF-D-04, RF-PD-10, ADR-054), sin perder nada en silencio.
 *
 * Se ejecuta `merge_observability` de update.sh de verdad, cargado con `source`,
 * sobre dos directorios temporales: el del cliente (editado) y el del paquete
 * nuevo (pristino), con la huella `observability.sha256` del paquete anterior.
 * Las reglas de quien gana en cada fichero estan en lib/observability-merge.sh.
 */

/**
 * @param  array<string, string>  $pristine  ficheros tal como los entrego el paquete anterior
 * @param  array<string, string>  $client  ficheros que tiene ahora el cliente
 * @param  array<string, string>  $new  ficheros del paquete nuevo
 * @param  array<string, string>  $links  enlaces simbolicos del cliente (nombre => destino)
 * @param  string  $before  codigo de shell previo a la fusion
 * @param  array<string, string>  $env  entorno del proceso
 * @return array{0: string, 1: Process}
 */
function observabilityMergeScenario(array $pristine, array $client, array $new, bool $withBaseline = true, string $then = '', array $links = [], string $before = '', array $env = []): array
{
    $root = sys_get_temp_dir().'/kq-obs-merge-'.bin2hex(random_bytes(4));
    foreach (['old' => $client, 'new' => $new, 'pristine' => $pristine] as $name => $files) {
        mkdir($root.'/'.$name.'/observability', 0777, true);
        foreach ($files as $path => $content) {
            $file = $root.'/'.$name.'/observability/'.$path;
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
    }

    foreach ($links as $name => $target) {
        symlink($target, $root.'/old/observability/'.$name);
    }

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; CURRENT_DIR='.escapeshellarg($root.'/old').'; PACKAGE_DIR='.escapeshellarg($root.'/new').'; '
        .($withBaseline ? 'kq_obs_manifest '.escapeshellarg($root.'/pristine/observability').' >"${CURRENT_DIR}/observability.sha256"; ' : '')
        .$before.'merge_observability; '.$then;

    $process = new Process(['bash', '-c', $script], env: array_merge(['KRONOQR_LANG' => 'es'], $env), timeout: 60.0);
    $process->run();

    return [$root, $process];
}

it('lleva al directorio nuevo lo que SOLO cambio el cliente, y avisa', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['blackbox/blackbox.yml' => "umbral: 300\n", 'grafana/a.json' => "a\n"],
        client: ['blackbox/blackbox.yml' => "umbral: 600\n", 'grafana/a.json' => "a\n", 'grafana/mio.json' => "mio\n"],
        new: ['blackbox/blackbox.yml' => "umbral: 300\n", 'grafana/a.json' => "a\n"],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('Tus cambios en observability/ viajan al directorio nuevo (2 ficheros')
        ->and($process->getOutput())->toContain('blackbox/blackbox.yml')
        ->and($process->getOutput())->toContain('grafana/mio.json')
        ->and(file_get_contents($root.'/new/observability/blackbox/blackbox.yml'))->toBe("umbral: 600\n")
        ->and(file_get_contents($root.'/new/observability/grafana/mio.json'))->toBe("mio\n");
})->group('RNF-D-04', 'RF-PD-10');

it('lo nuevo del paquete gana en lo que el cliente no toco, y en silencio', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['prometheus/rules/kiosk.yml' => "v1\n"],
        client: ['prometheus/rules/kiosk.yml' => "v1\n"],
        new: ['prometheus/rules/kiosk.yml' => "v2\n", 'prometheus/rules/nueva.yml' => "nueva\n"],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('observability/')
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/kiosk.yml'))->toBe("v2\n")
        ->and(file_exists($root.'/new/observability/prometheus/rules/nueva.yml'))->toBeTrue();
})->group('RNF-D-04');

it('ante un conflicto gana el paquete, se avisa y la copia del cliente queda en el directorio anterior', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['a.yml' => "v1\n", 'b.yml' => "b1\n"],
        client: ['a.yml' => "mio\n", 'b.yml' => "mio\n"],
        // a.yml lo cambian los dos; b.yml el paquete lo ha retirado.
        new: ['a.yml' => "v2\n"],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->toContain('GANA EL DEL PAQUETE')
        ->and($process->getOutput().$process->getErrorOutput())->toContain('a.yml, b.yml')
        ->and(file_get_contents($root.'/new/observability/a.yml'))->toBe("v2\n")
        ->and(file_exists($root.'/new/observability/b.yml'))->toBeFalse()
        ->and(file_get_contents($root.'/old/observability/a.yml'))->toBe("mio\n")
        ->and(file_get_contents($root.'/old/observability/b.yml'))->toBe("mio\n");
})->group('RNF-D-04');

it('sin la huella del paquete anterior no adivina: gana el paquete, avisa una vez y no pierde nada', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: [],
        client: ['a.yml' => "mio\n", 'igual.yml' => "x\n"],
        new: ['a.yml' => "v2\n", 'igual.yml' => "x\n"],
        withBaseline: false,
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->toContain('no trae la huella de observability/')
        ->and($process->getOutput().$process->getErrorOutput())->toContain('1 ficheros difieren')
        ->and(file_get_contents($root.'/new/observability/a.yml'))->toBe("v2\n")
        ->and(file_get_contents($root.'/old/observability/a.yml'))->toBe("mio\n");
})->group('RNF-D-04');

it('sin la huella y sin diferencias no dice nada', function (): void {
    [, $process] = observabilityMergeScenario(
        pristine: [],
        client: ['a.yml' => "x\n"],
        new: ['a.yml' => "x\n"],
        withBaseline: false,
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('observability/');
})->group('RNF-D-04');

it('es idempotente: un reintento tras una vuelta atras no vuelve a llevar nada', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['a.yml' => "v1\n"],
        client: ['a.yml' => "mio\n"],
        new: ['a.yml' => "v1\n"],
        then: 'merge_observability',
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        // La segunda pasada ya ve el fichero igual en los dos lados: calla.
        ->and(substr_count($process->getOutput(), 'viajan al directorio nuevo'))->toBe(1)
        ->and(file_get_contents($root.'/new/observability/a.yml'))->toBe("mio\n");
})->group('RNF-D-04');

it('package.sh entrega la huella de observability/ tal como se entrega', function (): void {
    $dest = sys_get_temp_dir().'/kq-package-'.bin2hex(random_bytes(4));
    $process = new Process(['bash', Repo::file('infra/scripts/package.sh'), $dest], timeout: 60.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_exists($dest.'/observability.sha256'))->toBeTrue();

    $check = Process::fromShellCommandline('cd '.escapeshellarg($dest.'/observability').' && sha256sum -c ../observability.sha256 >/dev/null', timeout: 60.0);
    $check->run();

    expect($check->getExitCode())->toBe(0, $check->getErrorOutput().$check->getOutput());
})->group('RNF-D-04', 'RF-PD-10');

it('un fichero BORRADO por el cliente que el paquete no cambia se borra tambien en el nuevo, y se dice por su nombre', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['grafana/a.json' => "a\n", 'grafana/b.json' => "b\n"],
        client: ['grafana/b.json' => "b\n"],
        new: ['grafana/a.json' => "a\n", 'grafana/b.json' => "b\n"],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('Has borrado 1 ficheros')
        ->and($process->getOutput())->toContain('grafana/a.json')
        ->and(file_exists($root.'/new/observability/grafana/a.json'))->toBeFalse()
        ->and(file_exists($root.'/new/observability/grafana/b.json'))->toBeTrue();
})->group('RNF-D-04');

it('un fichero borrado por el cliente que el paquete SI cambia vuelve, con aviso por su nombre', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['grafana/a.json' => "a1\n"],
        client: [],
        new: ['grafana/a.json' => "a2\n"],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->toContain('grafana/a.json (borrado)')
        ->and(file_get_contents($root.'/new/observability/grafana/a.json'))->toBe("a2\n");
})->group('RNF-D-04');

it('la lista protegida: gana siempre el paquete si el cliente cambia o borra una regla de serie, y local-* viaja', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: [
            'prometheus/rules/work-record.yml' => "v1\n",
            'prometheus/rules/audit.yml' => "v1\n",
            'loki/loki.yaml' => "retencion: 90\n",
        ],
        // work-record.yml editada (el paquete NO la cambia), audit.yml borrada, loki editada,
        // y una regla propia local-*.yml.
        client: [
            'prometheus/rules/work-record.yml' => "apagada\n",
            'loki/loki.yaml' => "retencion: 1\n",
            'prometheus/rules/local-hotel.yml' => "groups: []\n",
        ],
        new: [
            'prometheus/rules/work-record.yml' => "v1\n",
            'prometheus/rules/audit.yml' => "v1\n",
            'loki/loki.yaml' => "retencion: 90\n",
        ],
    );
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($salida)->toContain('GANA EL DEL PAQUETE, siempre')
        ->and($salida)->toContain('prometheus/rules/work-record.yml')
        ->and($salida)->toContain('prometheus/rules/audit.yml (borrado)')
        ->and($salida)->toContain('loki/loki.yaml')
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/work-record.yml'))->toBe("v1\n")
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/audit.yml'))->toBe("v1\n")
        ->and(file_get_contents($root.'/new/observability/loki/loki.yaml'))->toBe("retencion: 90\n")
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/local-hotel.yml'))->toBe("groups: []\n")
        // La copia del cliente sigue en el directorio anterior.
        ->and(file_get_contents($root.'/old/observability/prometheus/rules/work-record.yml'))->toBe("apagada\n");
})->group('RNF-D-04', 'RS-07');

it('los enlaces simbolicos del cliente no se llevan y se avisa por su nombre', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['a.yml' => "x\n"],
        client: ['a.yml' => "x\n"],
        new: ['a.yml' => "x\n"],
        links: ['enlace.yml' => '/etc/passwd'],
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->toContain('enlace.yml')
        ->and(file_exists($root.'/new/observability/enlace.yml'))->toBeFalse();
})->group('RNF-D-04');

it('solo lo legal o de seguridad esta protegido: kiosk.yml editado viaja, prometheus.yml.template no', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['prometheus/rules/kiosk.yml' => "umbral: 300\n", 'prometheus/prometheus.yml.template' => "rule_files: [rules/*.yml]\n"],
        client: ['prometheus/rules/kiosk.yml' => "umbral: 600\n", 'prometheus/prometheus.yml.template' => "rule_files: []\n"],
        new: ['prometheus/rules/kiosk.yml' => "umbral: 300\n", 'prometheus/prometheus.yml.template' => "rule_files: [rules/*.yml]\n"],
    );
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/kiosk.yml'))->toBe("umbral: 600\n")
        ->and(file_get_contents($root.'/new/observability/prometheus/prometheus.yml.template'))->toBe("rule_files: [rules/*.yml]\n")
        ->and($salida)->toContain('GANA EL DEL PAQUETE, siempre')
        ->and($salida)->toContain('prometheus/prometheus.yml.template');
})->group('RNF-D-04', 'RS-07');

it('una regla AÑADIDA por el cliente que no es local-* no viaja sin promtool y queda en aviso', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['prometheus/rules/kiosk.yml' => "k\n"],
        client: ['prometheus/rules/kiosk.yml' => "k\n", 'prometheus/rules/mia.yml' => "groups: []\n", 'prometheus/rules/local-mia.yml' => "groups: []\n"],
        new: ['prometheus/rules/kiosk.yml' => "k\n"],
        then: 'command -v promtool >/dev/null 2>&1 && echo HAY_PROMTOOL || true',
    );
    $salida = $process->getOutput().$process->getErrorOutput();

    if (str_contains($salida, 'HAY_PROMTOOL')) {
        Assert::markTestSkipped('promtool disponible: la validacion real decide');
    }

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_exists($root.'/new/observability/prometheus/rules/mia.yml'))->toBeFalse()
        ->and($salida)->toContain('prometheus/rules/mia.yml (regla propia sin validar)')
        ->and(file_exists($root.'/new/observability/prometheus/rules/local-mia.yml'))->toBeTrue();
})->group('RNF-D-04');

it('la biblioteca se carga sola (como hace 8b), la huella no sale con hashes vacios y una huella corrupta no se interpreta', function (): void {
    $dir = sys_get_temp_dir().'/kq-obs-lib-'.bin2hex(random_bytes(4));
    mkdir($dir.'/o', 0777, true);
    mkdir($dir.'/n', 0777, true);
    file_put_contents($dir.'/o/a.yml', "x\n");
    file_put_contents($dir.'/n/a.yml', "y\n");
    $lib = escapeshellarg(Repo::file('infra/scripts/lib/observability-merge.sh'));

    // 1. Cargada SOLA: sha256_of viene de fs.sh, cargada por la propia biblioteca.
    $sola = Process::fromShellCommandline('bash -c '.escapeshellarg('set -euo pipefail; . '.$lib.'; kq_obs_manifest '.$dir.'/o'), timeout: 30.0);
    $sola->run();
    expect($sola->getExitCode())->toBe(0, $sola->getErrorOutput())
        ->and($sola->getOutput())->toMatch('/^[0-9a-f]{64}  a\.yml$/m');

    // 2. Un hash vacio hace FALLAR la huella en vez de escribir una corrupta.
    $vacio = Process::fromShellCommandline('bash -c '.escapeshellarg('set -euo pipefail; . '.$lib.'; sha256_of() { :; }; kq_obs_manifest '.$dir.'/o'), timeout: 30.0);
    $vacio->run();
    expect($vacio->getExitCode())->not->toBe(0);

    // 3. Una huella con ruta absoluta, con `..` o con un hash que no es SHA-256 se ignora.
    file_put_contents($dir.'/huella', "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  /etc/passwd\n".str_repeat('b', 64)."  ../fuera\nno-es-un-hash  a.yml\n");
    $corrupta = Process::fromShellCommandline(
        'bash -c '.escapeshellarg('set -euo pipefail; . '.$lib.'; kq_obs_merge '.$dir.'/o '.$dir.'/n '.$dir.'/huella; echo "carried=${#KQ_OBS_CARRIED[@]} conflicts=${#KQ_OBS_CONFLICTS[@]}"'),
        timeout: 30.0,
    );
    $corrupta->run();
    // Sin entradas validas B esta vacio: a.yml (distinto en los dos lados) se trata como "anadido por el cliente"
    // y como el paquete tiene el suyo, es un conflicto: gana el paquete y nada se lleva.
    expect($corrupta->getExitCode())->toBe(0, $corrupta->getErrorOutput())
        ->and($corrupta->getOutput())->toContain('carried=0')
        ->and(file_get_contents($dir.'/n/a.yml'))->toBe("y\n");
})->group('RNF-D-04');

it('el cliente BORRA una regla de serie (projection.yml) que el paquete no cambia: vuelve, con aviso por nombre', function (): void {
    [$root, $process] = observabilityMergeScenario(
        pristine: ['prometheus/rules/projection.yml' => "p\n", 'prometheus/rules/incidents.yml' => "i\n", 'prometheus/rules/alerting.yml' => "a\n"],
        client: ['prometheus/rules/incidents.yml' => "apagada\n", 'prometheus/rules/alerting.yml' => "a\n"],
        new: ['prometheus/rules/projection.yml' => "p\n", 'prometheus/rules/incidents.yml' => "i\n", 'prometheus/rules/alerting.yml' => "a\n"],
    );
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($salida)->toContain('prometheus/rules/projection.yml (borrado)')
        ->and($salida)->toContain('prometheus/rules/incidents.yml')
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/projection.yml'))->toBe("p\n")
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/incidents.yml'))->toBe("i\n");
})->group('RNF-D-04', 'RS-07');

it('las reglas que viajan se validan con el promtool de la imagen de Prometheus: si lo rechaza, gana el paquete', function (string $codigo, bool $viaja): void {
    $bin = sys_get_temp_dir().'/kq-obs-docker-'.bin2hex(random_bytes(4));
    mkdir($bin, 0777, true);
    // docker falso: `info` responde y `run` sale con el codigo pedido (la validacion).
    file_put_contents($bin.'/docker', "#!/bin/sh\nif [ \"\$1\" = info ]; then exit 0; fi\necho \"\$@\" >>".$bin."/llamadas\nexit ".$codigo."\n");
    chmod($bin.'/docker', 0755);

    [$root, $process] = observabilityMergeScenario(
        pristine: ['prometheus/rules/kiosk.yml' => "umbral: 300\n"],
        client: ['prometheus/rules/kiosk.yml' => "umbral: 600\n"],
        new: ['prometheus/rules/kiosk.yml' => "umbral: 300\n"],
        before: 'COMPOSE_FILE="${CURRENT_DIR}/c.yml"; printf "    image: prom/prometheus:v3.1.0\n" >"${COMPOSE_FILE}"; ',
        env: ['PATH' => $bin.':'.getenv('PATH')],
    );
    $salida = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $salida)
        ->and((string) file_get_contents($bin.'/llamadas'))->toContain('--entrypoint promtool')->toContain('prom/prometheus:v3.1.0')->toContain('check rules')
        ->and(file_get_contents($root.'/new/observability/prometheus/rules/kiosk.yml'))->toBe($viaja ? "umbral: 600\n" : "umbral: 300\n");
    if (! $viaja) {
        expect($salida)->toContain('promtool la rechaza');
    }
})->with([
    'la valida' => ['0', true],
    'la rechaza' => ['1', false],
])->group('RNF-D-04');
