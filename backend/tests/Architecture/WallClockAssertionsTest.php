<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;
use Tests\Support\Time\WallClockBudget;

/*
 * Ningun presupuesto de reloj en una suite funcional (D4 de la 2.3.0; Q2,
 * V21-2-QA-2).
 *
 * Una afirmacion contra el reloj de pared falla cuando la maquina va cargada,
 * no cuando el producto empeora: `PeriodReportVolumeTest` puso `main` en rojo
 * tres veces. Decision del propietario: la afirmacion funcional se queda en su
 * suite y la de tiempo pasa a la suite nocturna de rendimiento —el grupo
 * `performance`, con la mediana de varias pasadas—. Esta prueba es la puerta
 * que impide que vuelva a entrar un reloj por otro sitio:
 *
 * - todo fichero de prueba que lee el reloj de pared (`hrtime`, `microtime`) lo
 *   hace a traves de {@see WallClockBudget}, cuyos metodos de afirmacion lanzan
 *   fuera del grupo `performance`; o figura abajo, con el motivo por el que su
 *   lectura no es un presupuesto;
 * - `phpunit.xml` saca ese grupo de toda pasada normal, y el Makefile y la CI
 *   lo ejecutan de noche.
 */

/**
 * Ficheros que leen el reloj sin afirmar un techo de tiempo, con su motivo.
 * Una lectura del reloj que solo puede FALLAR POR RAPIDA no depende de la carga
 * de la maquina.
 */
const WALL_CLOCK_ASSERTIONS_SIN_PRESUPUESTO = [
    'tests/Feature/Attendance/UnprocessableScanDiscardTest.php' => 'afirma un SUELO (el relleno hasta el doble del de RS-03): una maquina cargada solo lo aleja',
    'tests/Feature/Health/CircuitBreakerWiringTest.php' => 'escribe un instante en el fichero del cortacircuitos; no mide nada',
    'tests/Integration/Database/ParallelRequestsBarrierTest.php' => 'compara el ORDEN de dos lecturas del reloj monotono (soltar antes que empezar), no una duracion: una maquina cargada no lo invierte',
    'tests/Integration/Install/InstallInterruptionTest.php' => 'plazo de espera de un proceso hijo, no una afirmacion de tiempo',
    'tests/Integration/Schema/MigrationsRoundTripTest.php' => 'solo informa de lo que tardan las migraciones; no lo afirma',
    'tests/Integration/Schema/UnusedJsonbIndexesMigrationTest.php' => 'afirma que una migracion ESPERA a un bloqueo (cota inferior): una maquina cargada solo la alarga',
];

/**
 * Lo mismo en Playwright: los ficheros de E2E que declaran un presupuesto
 * (`*_BUDGET_MS`) o restan dos lecturas del reloj, cada uno con donde corre y
 * por que. Los NOCTURNOS (empiezan por «nocturna») viven en un proyecto que
 * solo existe con KRONOQR_E2E_PERFORMANCE=1; la prueba de abajo lo comprueba en
 * su playwright.config.ts. Los demas corren en cada push con un motivo escrito:
 * un presupuesto nuevo en una suite funcional no entra sin pasar por aqui.
 */
const WALL_CLOCK_ASSERTIONS_E2E = [
    'frontend-admin/tests/e2e/perf-large-tables.spec.ts' => 'nocturna: proyecto admin-perf; RNF-P-04 con la mediana de tres muestras (D4)',
    'frontend-kiosk/tests/e2e/perf-scan-latency.spec.ts' => 'nocturna: proyecto kiosk-perf-latency; RNF-P-01 con p95 y p99 de 60 escaneos (KP3)',
    'frontend-kiosk/tests/e2e/kiosk-upgrade.spec.ts' => 'avanza el reloj simulado lo que ha pasado de verdad; no afirma ninguna duracion',
    'frontend-kiosk/tests/e2e/perf-lcp.spec.ts' => 'en cada push por decision de KP1 (2.2.1): mediana de tres cargas en frio, margen medido del 32 % en la CI; caza el orden de magnitud, no un 5 %',
    'frontend-kiosk/tests/e2e/perf-scanner-ready.spec.ts' => 'en cada push por la tarea 17.10: mediana de varias cargas, 5 s frente a 3,4-3,5 s medidos; avisa por encima de 4 s',
    'frontend-kiosk/tests/e2e/qr-decode-budget.spec.ts' => 'en cada push: mediana de 21 decodificaciones tras 5 de calentamiento, factor 18 sobre lo medido y por debajo de la regresion que caza (22 ms)',
];

/** Un presupuesto declarado o una duracion medida con el reloj del navegador o de Node. */
const WALL_CLOCK_ASSERTIONS_E2E_PATTERN = '/\b[A-Z][A-Z0-9_]*_BUDGET_MS\b|\b(?:Date|performance)\.now\(\)\s*-\s*[A-Za-z_]/';

/**
 * Los ficheros de E2E de las tres SPA cuyo texto encaja con el patron,
 * relativos a la raiz del repositorio.
 *
 * @return list<string>
 */
function wallClockAssertionsE2eReaders(): array
{
    $found = [];

    foreach (['frontend-kiosk', 'frontend-admin', 'frontend-portal'] as $app) {
        $finder = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Repo::file($app.'/tests/e2e'), FilesystemIterator::SKIP_DOTS));

        foreach ($finder as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.ts')
                && preg_match(WALL_CLOCK_ASSERTIONS_E2E_PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = ModuleTree::relative($file->getPathname(), Repo::root());
            }
        }
    }

    sort($found);

    return $found;
}

/**
 * Los ficheros de prueba de las suites funcionales cuyo texto encaja con
 * `$pattern`, relativos a backend/.
 *
 * @return list<string>
 */
function wallClockAssertionsFilesMatching(string $pattern): array
{
    $tests = dirname(__DIR__);
    $found = [];

    foreach (['Unit', 'Integration', 'Feature', 'Contract'] as $suite) {
        foreach (ModuleTree::phpFilesUnder($tests.'/'.$suite) as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                $found[] = 'tests/'.ModuleTree::relative($file, $tests);
            }
        }
    }

    sort($found);

    return $found;
}

/** El texto de un fichero de prueba, relativo a backend/, del mismo arbol que se ha recorrido. */
function wallClockAssertionsSource(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
}

/** @return list<string> */
function wallClockAssertionsReaders(): array
{
    return wallClockAssertionsFilesMatching('/\b(?:hrtime|microtime)\s*\(/');
}

it('no lee el reloj de pared en una prueba funcional salvo a traves del presupuesto de la suite de rendimiento', function (): void {
    $sinPasar = array_values(array_filter(
        wallClockAssertionsReaders(),
        static fn (string $file): bool => ! array_key_exists($file, WALL_CLOCK_ASSERTIONS_SIN_PRESUPUESTO)
            && ! str_contains(wallClockAssertionsSource($file), 'WallClockBudget::'),
    ));

    expect($sinPasar)->toBe(
        [],
        'Estas pruebas leen el reloj de pared sin pasar por WallClockBudget: un techo de tiempo va en '
        ."una prueba con ->group('performance') y WallClockBudget::expectMedianBelow(), y la afirmacion "
        .'funcional en otra, sin reloj (D4). Si la lectura no es un presupuesto, anadela a la lista '
        .'de este fichero con su motivo.',
    );
})->group('RQ-14');

it('solo exime ficheros que existen y siguen leyendo el reloj', function (): void {
    // Una excepcion que sobrevive a su fichero es un agujero esperando a alguien.
    expect(array_values(array_diff(array_keys(WALL_CLOCK_ASSERTIONS_SIN_PRESUPUESTO), wallClockAssertionsReaders())))
        ->toBe([]);
})->group('RQ-14');

it('no mide tiempo en un E2E salvo en los ficheros listados con donde corren y por que', function (): void {
    expect(array_values(array_diff(wallClockAssertionsE2eReaders(), array_keys(WALL_CLOCK_ASSERTIONS_E2E))))->toBe(
        [],
        'Estos E2E declaran un presupuesto de reloj o miden una duracion sin figurar en '
        .'WALL_CLOCK_ASSERTIONS_E2E: el presupuesto va en un perf-*.spec.ts del proyecto nocturno '
        .'(KRONOQR_E2E_PERFORMANCE=1) y la afirmacion funcional en su suite, sin reloj (D4).',
    );
})->group('RQ-14');

it('solo lista E2E que existen y siguen midiendo tiempo', function (): void {
    expect(array_values(array_diff(array_keys(WALL_CLOCK_ASSERTIONS_E2E), wallClockAssertionsE2eReaders())))
        ->toBe([]);
})->group('RQ-14');

it('deja fuera de cada push los E2E de rendimiento nocturnos', function (string $config, string $proyectoDelPush, string $fichero): void {
    $texto = Repo::contents($config);
    $nocturnos = array_keys(array_filter(
        WALL_CLOCK_ASSERTIONS_E2E,
        static fn (string $motivo): bool => str_starts_with($motivo, 'nocturna'),
    ));

    expect($nocturnos)->toContain($fichero)
        ->and($texto)->toContain("process.env['KRONOQR_E2E_PERFORMANCE'] === '1'")
        ->and($texto)->toContain('...(PERFORMANCE_SUITE')
        ->and($texto)->toContain($proyectoDelPush);
})->with([
    'panel: el proyecto admin ignora perf-*.spec.ts' => [
        'frontend-admin/playwright.config.ts',
        "name: 'admin',\n      testIgnore: PERFORMANCE_SPECS,",
        'frontend-admin/tests/e2e/perf-large-tables.spec.ts',
    ],
    'quiosco: kiosk-qr ignora la latencia de 60 escaneos' => [
        'frontend-kiosk/playwright.config.ts',
        'perf-scan-latency\.spec\.ts$/,',
        'frontend-kiosk/tests/e2e/perf-scan-latency.spec.ts',
    ],
])->group('RQ-14');

it('afirma los presupuestos de reloj solo en pruebas del grupo de rendimiento', function (): void {
    // La otra mitad de la puerta, la de dentro de los ficheros: la guarda de
    // WallClockBudget lanza en ejecucion; esto lo dice antes de ejecutar nada.
    // Cada `expect*` de WallClockBudget tiene que estar en una prueba cuya
    // cadena lleve ->group(..., 'performance').
    $sinGrupo = [];

    // Las pruebas de la propia guarda la llaman fuera del grupo a proposito.
    $usuarios = array_diff(
        wallClockAssertionsFilesMatching('/WallClockBudget::expect\w+\(/'),
        ['tests/Unit/Quality/WallClockBudgetTest.php'],
    );

    expect($usuarios)->not->toBe([]);

    foreach ($usuarios as $file) {
        $source = wallClockAssertionsSource($file);
        preg_match_all('/WallClockBudget::expect\w+\(/', $source, $llamadas, PREG_OFFSET_CAPTURE);

        foreach ($llamadas[0] as [, $offset]) {
            $resto = substr($source, $offset);
            $cierre = preg_match('/\n\}\)(?<cadena>[^;]*);/', $resto, $prueba) === 1 ? $prueba['cadena'] : '';

            if (! str_contains($cierre, "'".WallClockBudget::GROUP."'")) {
                $sinGrupo[] = $file.':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($sinGrupo)->toBe([]);
})->group('RQ-14');

it('saca el grupo de rendimiento de toda pasada normal y lo ejecuta de noche', function (): void {
    $phpunit = Repo::contents('backend/phpunit.xml');
    $makefile = Repo::contents('Makefile');
    $ci = Repo::contents('.github/workflows/ci.yml');

    expect($phpunit)->toMatch('#<groups>\s*<exclude>\s*<group>performance</group>\s*</exclude>\s*</groups>#')
        ->and($makefile)->toContain("\t\$(RUN_APP) php -d memory_limit=\$(TEST_MEMORY_LIMIT) \$(PEST) --group=performance\n")
        ->and($ci)->toMatch('/\n  performance:\n(?:.*\n){0,12}    if: github\.event_name == \'schedule\' \|\| github\.event_name == \'workflow_dispatch\'\n/')
        ->and($ci)->toContain('run: bash .github/scripts/pest-max-skipped.sh run 0 "make test-performance"')
        ->and($ci)->toMatch('/\n  performance-kiosk:\n(?:.*\n){0,12}    if: github\.event_name == \'schedule\' \|\| github\.event_name == \'workflow_dispatch\'\n/')
        ->and($ci)->toContain('run: make e2e-performance');
})->group('RQ-14');
