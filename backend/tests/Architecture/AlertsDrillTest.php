<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\AlertRules;
use Tests\Architecture\Support\Repo;

/*
 * EL SIMULACRO DE ALERTAS (V4-DV-3, RF-PD-13, RNF-D-01).
 *
 * `make alerts-drill` (infra/drill/) dispara a proposito, en una pila efimera,
 * las alertas que nunca habian saltado de verdad. Estas pruebas no lo ejecutan
 * —necesita Docker—, lo ejecuta `.github/workflows/alerts-drill.yml`—. Vigilan
 * lo que se erosiona sin ruido:
 *
 *   · que el inventario del simulacro (las 34 de la 2.2.0 y las que se han ido anadiendo) siga completo y apunte a reglas que existen;
 *   · que cada receta toque series que el estado sano declara (una receta que
 *     cambia una serie inexistente la crea, y la alerta "salta" por otro motivo);
 *   · que las no fabricables tengan de verdad un caso de promtool que las dispare;
 *   · y, sobre todo, la guarda: el simulacro NUNCA se ejecuta contra una
 *     instalacion de cliente ni viaja en su paquete (SC7-03, mismo criterio que
 *     la semilla de desarrollo).
 */

/**
 * El inventario del simulacro: las 34 alertas que nunca habian saltado en real
 * (docs/verificacion/2.2.0-verificacion-final-tanda-4.md) mas las que se han
 * anadido despues (bloque 9 de la 2.3.0: cola de trabajos, Reverb y Loki).
 *
 * @var list<string>
 */
$inventarioDelSimulacro = [
    'AlmacenDeMetricasAusente', 'ArchivadoDeWalFallando', 'CertificadoTlsCaducado', 'CertificadoTlsNoVerificable',
    'CertificadoTlsProximoACaducar', 'ColaOfflineAtascada', 'ConciliacionCompletaDelRegistroAusente',
    'ConciliacionDelRegistroAusente', 'CopiaDeSeguridadAusente', 'DescansoEntreJornadasInsuficiente',
    'DeteccionDeIncidenciasConFallos', 'DeteccionDePatronesConFallos', 'DiscoDeCopiasCasiLleno',
    'EntregaDeAlertasFallando', 'EspacioEnDiscoBajo', 'FicheroGeneradoSinRetirarPasadoSuPlazo',
    'KioskDiscardedScansAttributed', 'KioskQueueStorageDegraded', 'KioskUnreportedDiscards',
    'KronoqrManagementAdminAccountCreated', 'KronoqrManagementTwoFactorReset', 'MedicionDeWalAusente',
    'MetricaDeIncidenciasAusente', 'ParticionDeAuditoriaAusente', 'ParticionDeAuditoriaDelProximoAnoSinPreparar',
    'PurgaDeFicherosGeneradosNoPuedeBorrar', 'RechazoDeFirmaQr', 'ReconciliacionConFallos',
    'RoturaDeCadenaDeAuditoria', 'SaturacionDelBordeEnElFichaje', 'ScanBatchItemNotProcessed',
    'SimulacroDeRestauracionCaducado', 'SimulacroDeRestauracionNuncaEjecutado', 'SlotDeReplicacionParado',
    'ColaDeTrabajosAcumulada', 'ColaDeTrabajosSinProcesar', 'ReverbSinRespuesta', 'LokiSinRespuesta',
];

/**
 * Las filas de un .tsv del simulacro (sin comentarios ni vacias).
 *
 * @return list<list<string>>
 */
$filasDe = static function (string $relativo): array {
    $filas = [];

    foreach (file(Repo::file($relativo), FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        if (trim($linea) === '' || str_starts_with(ltrim($linea), '#')) {
            continue;
        }
        $filas[] = explode("\t", $linea);
    }

    return $filas;
};

/** Las claves de serie del estado sano: `metrica{etiquetas}` sin el valor. */
$seriesDelEstadoSano = static function (): array {
    $series = [];

    foreach (file(Repo::file('infra/drill/baseline.prom'), FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        if (trim($linea) === '' || str_starts_with(ltrim($linea), '#')) {
            continue;
        }
        $series[] = (string) preg_replace('/ [^ ]+$/', '', $linea);
    }

    return $series;
};

it('cubre las alertas del inventario, cada una con receta o con motivo, y ninguna otra', function () use ($inventarioDelSimulacro, $filasDe): void {
    $conReceta = array_values(array_unique(array_map(static fn (array $fila): string => $fila[0], $filasDe('infra/drill/faults.tsv'))));
    $sinReceta = array_map(static fn (array $fila): string => $fila[0], $filasDe('infra/drill/not-fabricable.tsv'));

    expect($inventarioDelSimulacro)->toHaveCount(38);

    $cubiertas = array_merge($conReceta, $sinReceta);
    sort($cubiertas);
    $esperadas = $inventarioDelSimulacro;
    sort($esperadas);

    expect($cubiertas)->toBe($esperadas, 'faults.tsv + not-fabricable.tsv tienen que ser exactamente el inventario del simulacro.');
    expect(array_intersect($conReceta, $sinReceta))->toBe([], 'Una alerta o se dispara o se declara no fabricable, no las dos cosas.');
})->group('RF-PD-13', 'RNF-D-01');

it('apunta solo a reglas que existen', function () use ($filasDe): void {
    $reglas = AlertRules::names();

    foreach (['infra/drill/faults.tsv', 'infra/drill/not-fabricable.tsv'] as $fichero) {
        foreach ($filasDe($fichero) as $fila) {
            expect(in_array($fila[0], $reglas, true))->toBeTrue($fichero.': la alerta '.$fila[0].' ya no existe en las reglas.');
        }
    }
})->group('RF-PD-13', 'RNF-D-01');

it('toca en cada receta series que el estado sano declara', function () use ($filasDe, $seriesDelEstadoSano): void {
    $sanas = $seriesDelEstadoSano();
    $nombresSanos = array_unique(array_map(static fn (string $serie): string => explode('{', $serie)[0], $sanas));

    // Lo que cada accion exige del estado sano: `set` cambia una serie que existe
    // (si no, la crearia y la alerta "saltaria" por otro motivo); `del` retira una
    // que existe; `none` no toca nada.
    $existe = static fn (string $accion, string $argumento): bool => match ($accion) {
        'set' => in_array((string) preg_replace('/ [^ ]+$/', '', $argumento), $sanas, true),
        'del' => str_contains($argumento, '{') ? in_array($argumento, $sanas, true) : in_array($argumento, $nombresSanos, true),
        'none' => true,
        default => false,
    };

    foreach ($filasDe('infra/drill/faults.tsv') as $fila) {
        expect($fila)->toHaveCount(3, 'faults.tsv: tres columnas separadas por tabulador (alerta, accion, argumento).');
        [$alerta, $accion, $argumento] = $fila;

        expect($existe($accion, $argumento))->toBeTrue(
            $alerta.': la receta ('.$accion.' '.$argumento.') no corresponde a ninguna serie de baseline.prom, o la accion es desconocida.',
        );
    }
})->group('RF-PD-13', 'RNF-D-01');

/**
 * Las alertas que algun caso de `promtool test rules` espera ver disparadas.
 *
 * @return list<string>
 */
$alertasQuePromtoolDispara = static function (): array {
    $disparadas = [];

    foreach (glob(Repo::file('infra/observability/prometheus/tests').'/*.test.yml') ?: [] as $fichero) {
        /** @var array{tests?: list<array{alert_rule_test?: list<array{alertname?: string, exp_alerts?: list<mixed>}>}>} $documento */
        $documento = Yaml::parseFile($fichero);

        foreach ($documento['tests'] ?? [] as $caso) {
            foreach ($caso['alert_rule_test'] ?? [] as $comprobacion) {
                if (($comprobacion['exp_alerts'] ?? []) !== []) {
                    $disparadas[] = $comprobacion['alertname'] ?? '';
                }
            }
        }
    }

    return $disparadas;
};

it('respalda cada alerta no fabricable con un caso de promtool que la dispara', function () use ($filasDe, $alertasQuePromtoolDispara): void {
    $disparadas = $alertasQuePromtoolDispara();

    foreach ($filasDe('infra/drill/not-fabricable.tsv') as $fila) {
        expect($fila)->toHaveCount(2, 'not-fabricable.tsv: dos columnas (alerta y motivo).');
        expect(in_array($fila[0], $disparadas, true))->toBeTrue(
            $fila[0].' se declara no fabricable pero ningun caso de promtool la dispara: quedaria sin probar de ninguna manera.',
        );
    }
})->group('RF-PD-13', 'RNF-D-01');

it('documenta cada alerta del simulacro en infra/drill/README.md', function () use ($inventarioDelSimulacro): void {
    $readme = Repo::contents('infra/drill/README.md');

    $sinDocumentar = array_values(array_filter(
        $inventarioDelSimulacro,
        static fn (string $alerta): bool => ! str_contains($readme, '`'.$alerta.'`'),
    ));

    expect($sinDocumentar)->toBe([], 'Estas alertas no salen en la tabla de infra/drill/README.md.');
})->group('RF-PD-13');

it('se niega fuera de desarrollo y solo toca su propio proyecto de Docker (SC7-03)', function (): void {
    $script = Repo::contents('infra/drill/alerts-drill.sh');

    // Lista de permitidos y no de prohibidos, como DevelopmentSeeder::refuseInProduction.
    expect($script)
        ->toContain('local | testing) ;;')
        ->toContain('docker-compose.yml')
        ->toContain('observability.sha256')
        ->toContain('readonly PROJECT="kq-drill"');

    // Toda orden de Docker pasa por compose(), que fija -p kq-drill y el fichero
    // de esta carpeta. Fuera de el solo se permite `command -v docker`.
    $ordenes = [];
    foreach (explode("\n", $script) as $numero => $linea) {
        if (str_starts_with(ltrim($linea), '#')) {
            continue;
        }
        // `docker-compose.yml` es el nombre del fichero que la guarda busca, no una orden.
        if (preg_match('/\bdocker(?![-.\w])/', $linea) === 1) {
            $ordenes[] = ($numero + 1).': '.trim($linea);
        }
    }

    $permitidas = array_values(array_filter(
        $ordenes,
        static fn (string $orden): bool => ! str_contains($orden, 'docker compose -p "${PROJECT}" -f "${DRILL_DIR}/compose.drill.yaml"')
            && ! str_contains($orden, 'command -v docker')
            && ! str_contains($orden, 'die 2 "no encuentro docker'),
    ));

    expect($permitidas)->toBe([], 'Una orden de Docker fuera de compose(): podria tocar otro proyecto.');

    // Nada de parar o borrar por filtro, imagen o nombre.
    expect($script)->not->toMatch('/docker\s+(stop|rm|kill|rmi|system|volume\s+rm|network\s+rm)/');
    expect(preg_match_all('/compose down/', $script))->toBe(1);
})->group('RF-PD-13', 'RNF-D-01');

it('la pila efimera no publica puertos ni crea volumenes y no usa el nombre de la pila kronoqr', function (): void {
    $compose = Repo::contents('infra/drill/compose.drill.yaml');

    expect($compose)->toContain('name: kq-drill');
    expect($compose)->not->toContain('ports:');
    expect($compose)->not->toMatch('/^volumes:/m');
    expect($compose)->not->toContain('container_name:');
    expect($compose)->not->toContain('kronoqr-');

    // Las imagenes salen de compose.prod.yaml (las exporta el script): ninguna version escrita a mano.
    expect($compose)->not->toMatch('/image:\s+(prom|grafana)\//');
})->group('RF-PD-13');

it('no viaja en el paquete de entrega ni en el contexto de ninguna imagen', function (): void {
    $paquete = Repo::contents('infra/scripts/package.sh');
    expect($paquete)->not->toContain('infra/drill');
    expect($paquete)->not->toContain('alerts-drill');

    $destino = sys_get_temp_dir().'/kronoqr-paquete-'.bin2hex(random_bytes(6));
    $proceso = new Process(['bash', Repo::file('infra/scripts/package.sh'), $destino], timeout: 120.0);
    $proceso->run();

    $encontrados = [];
    if ($proceso->isSuccessful()) {
        $buscar = new Process(['find', $destino, '-mindepth', '1', '(', '-iname', '*drill*', '-not', '-iname', 'restore-drill*', ')']);
        $buscar->run();
        $encontrados = array_values(array_filter(explode("\n", $buscar->getOutput())));
    }
    (new Process(['rm', '-rf', $destino]))->run();

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput().$proceso->getOutput());
    expect($encontrados)->toBe([], 'El simulacro de alertas aparece dentro del paquete del cliente.');

    // Ningun Dockerfile lo copia.
    foreach (glob(Repo::file('infra/docker').'/*/Dockerfile*') ?: [] as $dockerfile) {
        expect((string) file_get_contents($dockerfile))->not->toContain('infra/drill');
    }
})->group('RF-PD-13', 'RNF-D-01');

it('esta atado al Makefile, a shellcheck/shfmt y a su propio workflow con APP_ENV=testing', function (): void {
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toMatch('/^alerts-drill:/m');
    expect($makefile)->toContain('$(wildcard infra/drill/*.sh)');
    // Sin valor por omision de APP_ENV: la guarda lee el entorno real.
    expect($makefile)->not->toContain('APP_ENV=$${APP_ENV:-');

    $workflow = Repo::contents('.github/workflows/alerts-drill.yml');
    expect($workflow)
        ->toContain('APP_ENV: testing')
        ->toContain('infra/drill/alerts-drill.sh')
        ->toContain('infra/observability/**');

    // La puerta rapida de promtool (check + test de TODAS las reglas) sigue en la etapa 2.
    expect(Repo::contents('.github/workflows/ci.yml'))->toContain('make observability-check');
})->group('RF-PD-13', 'RNF-D-01');

it('el runbook de entrega de alertas explica el simulacro y avisa de que no es para el cliente', function (): void {
    $runbook = Repo::contents('docs/runbooks/entrega-de-alertas.md');

    expect($runbook)
        ->toContain('make alerts-drill')
        ->toContain('alertmanager_notifications_failed_total');
})->group('RF-PD-13');

it('renderizar publica el estado sano completo y aplica set y del (V4-DV-3)', function (): void {
    // El fallo que esto vigila: con la seleccion vacia, awk tomaba baseline.prom por
    // la seleccion y publicaba CERO series. El estado sano disparaba las `absent()` y
    // sus OK no probaban nada.
    $renderiza = static function (string $alerta): string {
        $proceso = new Process(
            ['bash', '-c', 'set -euo pipefail; . "$1"; renderizar "$2"', 'x', Repo::file('infra/drill/alerts-drill.sh'), $alerta],
            timeout: 30.0,
        );
        $proceso->run();
        expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput());

        return $proceso->getOutput();
    };

    $series = static fn (string $texto): array => array_values(array_filter(
        explode("\n", $texto),
        static fn (string $linea): bool => trim($linea) !== '' && ! str_starts_with($linea, '#'),
    ));

    $baseline = $series(Repo::contents('infra/drill/baseline.prom'));
    $sano = $series($renderiza(''));

    expect($sano)->toHaveCount(count($baseline));
    expect(implode("\n", $sano))->not->toContain('@NOW');

    // `set`: cambia el valor y no anade una serie.
    $conSet = $series($renderiza('ColaOfflineAtascada'));
    expect($conSet)->toHaveCount(count($baseline));
    expect($conSet)->toContain('kiosk_offline_queue_size{device="drill-kiosk-1"} 80');

    // `del`: retira la serie.
    $conDel = $series($renderiza('AlmacenDeMetricasAusente'));
    expect($conDel)->toHaveCount(count($baseline) - 1);
    expect(implode("\n", $conDel))->not->toContain('kronoqr_metrics_store_up');
})->group('RF-PD-13', 'RNF-D-01');
