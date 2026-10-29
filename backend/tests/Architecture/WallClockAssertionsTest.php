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
    'frontend-admin/tests/e2e/perf-live-presence.spec.ts' => 'nocturna, bloquea: proyecto admin-perf; RNF-P-04 tal como se promete, LCP de la presencia con 500 personas en la mediana de tres cargas en frio',
    'frontend-admin/tests/e2e/perf-large-tables.spec.ts' => 'nocturna, bloquea las tareas largas y avisa del tiempo hasta la primera fila: proyecto admin-perf; objetivo interno de los volumenes grandes (tareas largas de 200 y 300 ms, 1,5 s interactivo), que no es promesa (decision del propietario del 28-10-2026); el interactivo no tiene margen en la CI y solo avisa',
    'frontend-kiosk/tests/e2e/perf-scan-latency.spec.ts' => 'nocturna: proyecto kiosk-perf-latency; RNF-P-01 con p95 y p99 de 60 escaneos (KP3)',
    'frontend-kiosk/tests/e2e/kiosk-upgrade.spec.ts' => 'avanza el reloj simulado lo que ha pasado de verdad; no afirma ninguna duracion',
    'frontend-kiosk/tests/e2e/perf-lcp.spec.ts' => 'en cada push por decision de KP1 (2.2.1): mediana de tres cargas en frio, margen medido del 32 % en la CI; caza el orden de magnitud, no un 5 %',
    'frontend-kiosk/tests/e2e/perf-scanner-ready.spec.ts' => 'en cada push por la tarea 17.10: mediana de varias cargas, 5 s frente a 3,4-3,5 s medidos; avisa por encima de 4 s',
    'frontend-kiosk/tests/e2e/qr-decode-budget.spec.ts' => 'en cada push: mediana de 21 decodificaciones tras 5 de calentamiento, factor 18 sobre lo medido y por debajo de la regresion que caza (22 ms)',
];

/**
 * Un presupuesto o un objetivo declarado, una duracion medida con el reloj del
 * navegador o de Node, o un LCP leido del navegador (`live-presence.spec.ts`
 * lo afirmaba en cada push hasta la 2.3.0 sin que esta puerta lo viera).
 */
const WALL_CLOCK_ASSERTIONS_E2E_PATTERN = '/\b[A-Z][A-Z0-9_]*_(?:BUDGET|TARGET)_MS\b|\b(?:Date|performance)\.now\(\)\s*-\s*[A-Za-z_]|largest-contentful-paint/';

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

/**
 * Lo que, en un fichero que usa WallClockBudget, mide o juzga el tiempo fuera
 * de una prueba del grupo de rendimiento. Antes bastaba con que el fichero
 * nombrara `WallClockBudget::` en alguna parte para eximirlo entero, y una
 * prueba funcional del mismo fichero podia afirmar una duracion a pelo.
 *
 * - Una prueba sin `->group(…, 'performance')` no lee el reloj, no llama a
 *   WallClockBudget y no afirma sobre la duracion que devuelve un ayudante
 *   (`expect(…['seconds']…)`); puede imprimirla, que no falla por carga.
 * - Un ayudante de nivel superior que lee el reloj y devuelve la duracion
 *   suelta (sin la clave `seconds`) solo se llama desde pruebas del grupo: si
 *   no, una prueba funcional podria juzgarla sin que la regla anterior lo viera.
 *
 * @return list<string> «linea N: lo encontrado», en orden.
 */
function wallClockAssertionsOutsidePerformance(string $source): array
{
    $offences = [];
    $linea = static fn (int $offset): int => substr_count(substr($source, 0, $offset), "\n") + 1;

    preg_match_all('/^function\s+(?<nombre>\w+)\(.*?^\}/ms', $source, $ayudantes, PREG_SET_ORDER);
    $cronometros = array_values(array_map(
        static fn (array $ayudante): string => $ayudante['nombre'],
        array_filter(
            $ayudantes,
            static fn (array $ayudante): bool => preg_match('/\b(?:hrtime|microtime)\s*\(/', $ayudante[0]) === 1
                && preg_match('/\'seconds\'\s*=>/', $ayudante[0]) !== 1,
        ),
    ));
    $llamadaACronometro = $cronometros === [] ? '' : '|\b(?:'.implode('|', $cronometros).')\(';

    preg_match_all('/^(?:it|test)\(.*?^\}\)(?<cadena>[^;]*);/ms', $source, $pruebas, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($pruebas as $prueba) {
        if (str_contains($prueba['cadena'][0], "'".WallClockBudget::GROUP."'")) {
            continue;
        }

        $juzgaElTiempo = '/\b(?:hrtime|microtime)\s*\(|WallClockBudget::|\bexpect\([^;]*?\[\s*\'seconds\'\s*\]'.$llamadaACronometro.'/s';

        if (preg_match($juzgaElTiempo, $prueba[0][0], $hallado, PREG_OFFSET_CAPTURE) === 1) {
            $offences[] = 'linea '.$linea($prueba[0][1] + $hallado[0][1]).': '.$hallado[0][0];
        }
    }

    return $offences;
}

it('no lee el reloj de pared en una prueba funcional salvo a traves del presupuesto de la suite de rendimiento', function (): void {
    $sinPasar = array_values(array_filter(
        wallClockAssertionsReaders(),
        static fn (string $file): bool => ! array_key_exists($file, WALL_CLOCK_ASSERTIONS_SIN_PRESUPUESTO)
            && (! str_contains(wallClockAssertionsSource($file), 'WallClockBudget::')
                || wallClockAssertionsOutsidePerformance(wallClockAssertionsSource($file)) !== []),
    ));

    expect($sinPasar)->toBe(
        [],
        'Estas pruebas leen el reloj de pared sin pasar por WallClockBudget: un techo de tiempo va en '
        ."una prueba con ->group('performance') y WallClockBudget::expectMedianBelow(), y la afirmacion "
        .'funcional en otra, sin reloj (D4). Si la lectura no es un presupuesto, anadela a la lista '
        .'de este fichero con su motivo.',
    );
})->group('RQ-14');

it('no deja que una prueba funcional juzgue el tiempo por compartir fichero con WallClockBudget', function (string $fuente, int $infracciones): void {
    expect(wallClockAssertionsOutsidePerformance($fuente))->toHaveCount($infracciones);
})->with([
    'el ayudante mide y solo la prueba de rendimiento juzga' => [
        "<?php\nfunction medir(): array\n{\n    \$t = hrtime(true);\n\n    return ['seconds' => (hrtime(true) - \$t) / 1e9];\n}\n\nit('a', function () {\n    expect(medir())->toHaveKey('seconds');\n})->group('x');\n\nit('b', function () {\n    WallClockBudget::expectMedianBelow(WallClockBudget::samples(fn () => medir()['seconds']), 1.0, 'y');\n})->group('x', 'performance');\n",
        0,
    ],
    'una prueba funcional imprime la duracion sin juzgarla' => [
        "<?php\nit('a', function () {\n    \$d = medir();\n    fwrite(STDERR, round(\$d['seconds'], 3).\" s\\n\");\n    expect(\$d['body'])->not->toBe('');\n})->group('x');\n",
        0,
    ],
    'una prueba funcional afirma la duracion del ayudante' => [
        "<?php\nit('a', function () {\n    expect(medir()['seconds'])->toBeLessThan(5.0);\n})->group('x');\n",
        1,
    ],
    'una prueba funcional lee el reloj' => [
        "<?php\nit('a', function () {\n    \$t = microtime(true);\n    accion();\n    expect(microtime(true) - \$t)->toBeLessThan(1.0);\n})->group('x');\n",
        1,
    ],
    'una prueba funcional usa WallClockBudget' => [
        "<?php\nit('a', function () {\n    WallClockBudget::expectMedianBelow([0.1], 1.0, 'y');\n})->group('x');\n",
        1,
    ],
    'un ayudante que devuelve la duracion suelta, llamado solo desde rendimiento' => [
        "<?php\nit('b', function () {\n    WallClockBudget::expectRatioBelow(cronometrar(fn () => 1) / 1.0, 1.5, 'y');\n})->group('x', 'performance');\n\nfunction cronometrar(callable \$f): float\n{\n    \$t = hrtime(true);\n    \$f();\n\n    return (hrtime(true) - \$t) / 1e6;\n}\n",
        0,
    ],
    'un ayudante que devuelve la duracion suelta, llamado desde una prueba funcional' => [
        "<?php\nit('a', function () {\n    expect(cronometrar(fn () => 1))->toBeLessThan(50.0);\n})->group('x');\n\nfunction cronometrar(callable \$f): float\n{\n    \$t = hrtime(true);\n    \$f();\n\n    return (hrtime(true) - \$t) / 1e6;\n}\n",
        1,
    ],
])->group('RQ-14');

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
    'panel: el proyecto admin ignora tambien el LCP de la presencia' => [
        'frontend-admin/playwright.config.ts',
        "name: 'admin',\n      testIgnore: PERFORMANCE_SPECS,",
        'frontend-admin/tests/e2e/perf-live-presence.spec.ts',
    ],
    'quiosco: kiosk-qr ignora la latencia de 60 escaneos' => [
        'frontend-kiosk/playwright.config.ts',
        'perf-scan-latency\.spec\.ts$/,',
        'frontend-kiosk/tests/e2e/perf-scan-latency.spec.ts',
    ],
])->group('RQ-14');

it('en la nocturna del panel, la promesa bloquea entera y el objetivo interno solo en las tareas largas', function (string $fichero, bool $esPromesa): void {
    // Decision del propietario del 28-10-2026: RNF-P-04 es la presencia en
    // vivo con 500 empleados y es la unica promesa; los volumenes grandes son un
    // objetivo interno (doc 02 §10.1). Empezo avisando sin bloquear porque el
    // panel no lo cumplia; desde la 23.4 las tareas largas bloquean (70-160 ms
    // frente a 200 y 300), pero el tiempo hasta la primera fila (1,5 s) sigue
    // avisando: el doble de la API tarda casi la mitad y la CI va mas lenta que
    // el anfitrion, de modo que no hay margen para bloquear.
    $texto = Repo::contents($fichero);
    $motivo = WALL_CLOCK_ASSERTIONS_E2E[$fichero] ?? '';

    expect($motivo)->toStartWith('nocturna, bloquea')
        ->and(str_contains($texto, "'@RNF-P-04'"))->toBe($esPromesa)
        ->and($texto)->toContain('.toBeLessThan(')
        ->and(str_contains($texto, 'warnAboveTarget('))->toBe(! $esPromesa);
})->with([
    'LCP de la presencia con 500 personas' => ['frontend-admin/tests/e2e/perf-live-presence.spec.ts', true],
    'informe de 20 000 filas y 3 000 hallazgos' => ['frontend-admin/tests/e2e/perf-large-tables.spec.ts', false],
])->group('RQ-14', 'RNF-P-04');

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
