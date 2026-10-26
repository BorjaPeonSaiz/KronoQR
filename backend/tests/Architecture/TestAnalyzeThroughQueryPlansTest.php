<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * En las pruebas, `ANALYZE` solo se lanza por `QueryPlans::analyze()`.
 *
 * POR QUE EXISTE. Un `DB::statement('ANALYZE ...')` con la conexion de la
 * aplicacion no hace nada: el rol no es dueño de las tablas y PostgreSQL
 * contesta con un `WARNING` y sigue. La prueba cree tener estadisticas y el
 * planificador trabaja con lo que el autovacuum dejo de la prueba anterior. Asi
 * cayo `PeriodReportVolumeTest` en la CI de la 19a: `daily_totals` con
 * `reltuples = 0` y miles de paginas, un `Nested Loop` que releia 15.500 filas
 * 15.500 veces y 5,9 s contra un presupuesto de 5 (RNF-P-05). Con la conexion
 * de migracion tampoco basta si los datos no estan confirmados: otra sesion no
 * los ve.
 *
 * `QueryPlans::analyze()` usa el rol dueño y **falla si no deja
 * estadisticas**, asi que cualquiera de los dos errores se ve en la propia
 * prueba. Para sembrar y analizar en la misma sesion esta
 * `RefreshDatabaseAsOwner`; para datos confirmados, `CommittedDatabase`.
 *
 * QUE COMPRUEBA. Ningun fichero de `tests/` fuera de `QueryPlans` llama a
 * `statement('ANALYZE ...')`. `EXPLAIN (ANALYZE ...)` y `VACUUM (ANALYZE)` no
 * entran: ni son esto ni fallan en silencio de la misma manera.
 *
 * Recorre con `scandir` (`ModuleTree::phpFilesUnder`) por la trampa del bind
 * mount que documenta `SourceDiscoveryTest`.
 */

/** El unico sitio donde puede vivir el `ANALYZE` de las pruebas. */
const TEST_ANALYZE_THROUGH_QUERY_PLANS_HOME = 'Support/Database/QueryPlans.php';

it('no lanza ANALYZE en las pruebas fuera de QueryPlans::analyze()', function (): void {
    $tests = dirname(__DIR__);
    $offenders = [];

    foreach (ModuleTree::phpFilesUnder($tests) as $file) {
        $relative = ModuleTree::relative($file, $tests);

        if ($relative === TEST_ANALYZE_THROUGH_QUERY_PLANS_HOME || $relative === 'Architecture/TestAnalyzeThroughQueryPlansTest.php') {
            continue;
        }

        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        assert($source !== false);

        if (preg_match('/(?:::|->)statement\(\s*[\'"]\s*ANALYZE\b/i', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'Estas pruebas lanzan ANALYZE a mano; con el rol de la aplicacion no hace nada y en silencio. '
        .'Usa QueryPlans::analyze() (y RefreshDatabaseAsOwner o CommittedDatabase para que vea lo sembrado): '
        .implode(', ', $offenders),
    );
})->group('RQ-13');

it('la guarda reconoce lo que prohibe y deja pasar EXPLAIN y VACUUM', function (): void {
    $pattern = '/(?:::|->)statement\(\s*[\'"]\s*ANALYZE\b/i';

    expect(preg_match($pattern, "DB::statement('ANALYZE daily_totals');"))->toBe(1)
        ->and(preg_match($pattern, '$conexion->statement("analyze employees");'))->toBe(1)
        ->and(preg_match($pattern, "DB::select('EXPLAIN (ANALYZE, BUFFERS) SELECT 1');"))->toBe(0)
        ->and(preg_match($pattern, "\$dueno->statement('VACUUM (ANALYZE) daily_totals');"))->toBe(0)
        ->and(file_exists(dirname(__DIR__).'/'.TEST_ANALYZE_THROUGH_QUERY_PLANS_HOME))->toBeTrue();
})->group('RQ-13');
