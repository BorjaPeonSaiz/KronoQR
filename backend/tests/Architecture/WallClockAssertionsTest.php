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
