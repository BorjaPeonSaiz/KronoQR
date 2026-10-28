<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Compliance\DevelopmentSeedChecks;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaSnapshot;

/*
 * Todas las migraciones vuelven atras y se reaplican (RF-PD-10, tarea 5.7), y
 * la semilla de desarrollo deja el registro horario cuadrado con su auditoria
 * (ADR-057 §4, RL-04, RS-07, RN-13; bloque 11 de la 2.2.1).
 *
 * «Una migracion cuyo `down()` no se ha probado no tiene `down()`»
 * (skill /migracion-segura). Aqui se prueban TODOS los `down()` de una vez,
 * en orden inverso y sobre una base con volumen realista (la semilla completa,
 * con los 90 dias del `VolumeSeeder`), y despues todos los `up()` otra vez.
 * Lo que se afirma del esquema resultante es lo que el §9.4 llama invariantes
 * de base de datos: las restricciones de RN-01 y RN-02 estan presentes y
 * VALIDAS, y la cadena de auditoria verifica.
 *
 * ## El esquema vuelve a ser el mismo, paso a paso
 *
 * Que el ciclo termine no basta: un `down()` que deje un `GRANT`, una funcion,
 * un indice o una restriccion con otra definicion lo pasaba igual (R5-BD-04).
 * Por eso los `down()` se deshacen **de uno en uno** y se toma el catalogo tras
 * cada uno ({@see SchemaSnapshot}: tablas, columnas, indices, restricciones,
 * funciones, *triggers*, tipos, estadisticas y privilegios). Despues los `up()`
 * se aplican tambien de uno en uno, y tras cada uno el catalogo tiene que ser
 * **identico** al que dejo el `down()` correspondiente; tras el ultimo, al del
 * principio. Una diferencia nombra la migracion cuyo `down()` hay que mirar.
 * Y con todas deshechas solo queda la tabla `migrations`.
 *
 * ## Una sola siembra para dos comprobaciones
 *
 * La semilla completa cuesta en torno a noventa segundos, y hasta la revision
 * del bloque 11 se sembraba dos veces: aqui y en una prueba propia de la
 * conciliacion. Ahora esa conciliacion corre aqui, tras sembrar y antes de
 * deshacer nada ({@see DevelopmentSeedChecks}): lo que se afirma es que
 * `make seed` deja un entorno sin alerta de manipulacion, y cualquier semilla
 * que se añada mañana entra en la prueba sin tocarla.
 *
 * ## Por que CommittedDatabase y no RefreshDatabase
 *
 * `RefreshDatabase` envuelve la prueba en una transaccion sobre la conexion de
 * la aplicacion; la semilla escribiria dentro de ella y dejaria bloqueos de
 * fila y de tabla que un `DROP TABLE` del rol de migracion —otra conexion—
 * tendria que esperar para siempre. Y las comprobaciones diferidas de los
 * *triggers* de `shift_entries` (ADR-057) solo corren al confirmar. Aqui todo
 * se confirma, y el trait vacia la base al terminar con el rol propietario.
 *
 * ## El tiempo se anota, no se acota
 *
 * La ficha de la tarea pide «tiempo anotado»: se imprime al final para que
 * quien mire la salida de la suite vea cuanto tarda la migracion completa con
 * volumen. No se convierte en un umbral: dependeria de la maquina y fallaria
 * por razones ajenas al esquema.
 */

uses(CommittedDatabase::class);

it('deshace todas las migraciones y las reaplica sobre la semilla completa, que llega cuadrada con su auditoria', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $schema = $migrator->getSchemaBuilder();

    $metrics = sys_get_temp_dir().'/kronoqr-semilla-conciliacion-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $metrics);
    Config::set('observability.metrics.enabled', true);

    // Una historia canonica antes de nada: todas en un lote y en el orden de
    // sus nombres. Otras pruebas de este proceso deshacen una migracion suelta
    // (`--path`) y la reaplican en un lote posterior, y entonces deshacer de una
    // en una ya no recorre el orden en que se aplican desde cero, que es con lo
    // que se compara cada paso.
    [$refreshed, $refreshOutput] = Commands::run('migrate:refresh --database='.$name);

    expect($refreshed)->toBe(0, $refreshOutput);

    try {
        $seedStart = microtime(true);
        [$seeded, $seedOutput] = Commands::run('db:seed --force');
        $seedSeconds = microtime(true) - $seedStart;

        expect($seeded)->toBe(0, $seedOutput);

        // La semilla, antes de tocar el esquema: su contenido y su conciliacion
        // con la auditoria (ADR-057 §4).
        DevelopmentSeedChecks::assertExpectedContent();
        DevelopmentSeedChecks::assertReconciled($metrics);
    } finally {
        File::deleteDirectory($metrics);
    }

    $entries = $migrator->table('shift_entries')->count();

    /** @var list<string> $migrations */
    $migrations = $migrator->table('migrations')->orderBy('id')->pluck('migration')->all();
    $applied = count($migrations);

    expect($entries)->toBeGreaterThan(1000)
        ->and($applied)->toBeGreaterThan(0);

    // El esquema completo, migrado por el camino normal: la referencia del
    // ultimo paso de la vuelta.
    $atHead = SchemaSnapshot::of($migrator);

    // TODOS los down(), uno a uno y en orden inverso, sobre datos de verdad.
    // `$afterDown[$k]` es el esquema con las `$k` primeras migraciones,
    // alcanzado deshaciendo la `$k + 1`.
    $afterDown = [];
    $rollbackStart = microtime(true);

    for ($k = $applied - 1; $k >= 0; $k--) {
        [$rolledBack, $rollbackOutput] = Commands::run('migrate:rollback --database='.$name.' --step=1');

        expect($rolledBack)->toBe(0, $migrations[$k].': '.$rollbackOutput);

        $afterDown[$k] = SchemaSnapshot::of($migrator);
    }

    $rollbackSeconds = microtime(true) - $rollbackStart;

    expect($migrator->table('migrations')->count())->toBe(0)
        ->and($schema->hasTable('shift_entries'))->toBeFalse()
        ->and($schema->hasTable('audit_log'))->toBeFalse()
        ->and($schema->hasTable('employees'))->toBeFalse();

    // Sin ninguna migracion no queda nada del producto: ni una funcion, ni un
    // tipo, ni un privilegio por omision; solo la tabla `migrations`.
    $leftovers = array_values(array_filter(
        $afterDown[0],
        static fn (string $line): bool => ! str_starts_with($line, 'schema|')
            && ! str_starts_with($line, 'extension|')
            && preg_match('/\A(?:relation|column|index|constraint|sequence)\|migrations(?:_id_seq)?\|/', $line) !== 1,
    ));

    expect($leftovers)->toBe([], "Restos tras deshacer todas las migraciones:\n".implode("\n", $leftovers));

    // Y todos los up() otra vez, desde cero y uno a uno. Tras cada uno, el
    // esquema tiene que ser el mismo al que se llego deshaciendo: si no, el
    // down() de la migracion siguiente dejo un residuo o se dejo algo sin
    // deshacer (R5-BD-04). Tras el ultimo, el mismo que al principio.
    $mismatches = [];
    $migrateStart = microtime(true);

    foreach ($migrations as $k => $migration) {
        [$migrated, $migrateOutput] = Commands::run('migrate --database='.$name.' --path=database/migrations/'.$migration.'.php');

        expect($migrated)->toBe(0, $migration.': '.$migrateOutput);

        $expected = $k + 1 === $applied ? $atHead : $afterDown[$k + 1];
        $differences = SchemaSnapshot::differences($expected, SchemaSnapshot::of($migrator));

        if ($differences !== []) {
            $culprit = $k + 1 === $applied ? 'la migracion completa' : 'deshacer '.$migrations[$k + 1];
            $mismatches[] = $migration.' (frente a '.$culprit."):\n  ".implode("\n  ", array_slice($differences, 0, 20));
        }
    }

    $migrateSeconds = microtime(true) - $migrateStart;

    expect($mismatches)->toBe([], "El esquema no vuelve a ser el mismo:\n".implode("\n", $mismatches))
        ->and($migrator->table('migrations')->count())->toBe($applied)
        ->and($schema->hasTable('shift_entries'))->toBeTrue()
        ->and($migrator->table('shift_entries')->count())->toBe(0);

    // Invariantes de base de datos (§9.4): RN-01 y RN-02 presentes y validas.
    // Es la misma comprobacion que hace update.sh tras migrar.
    $rn01 = $migrator->table('pg_indexes')
        ->where('tablename', 'shift_entries')
        ->where('indexname', 'one_open_shift_per_employee')
        ->count();
    $rn02 = $migrator->table('pg_constraint')
        ->where('conname', 'shift_entries_no_overlap')
        ->value('convalidated');

    expect($rn01)->toBe(1)
        ->and($rn02)->toBeTrue();

    // La cadena de auditoria del esquema recien creado verifica (RL-04, RS-07).
    [$chain, $chainOutput] = Commands::run('compliance:verify-audit-chain');

    expect($chain)->toBe(0, $chainOutput);

    fwrite(STDOUT, sprintf(
        "\n[RF-PD-10] %d tramos sembrados en %.1f s · %d migraciones deshechas en %.1f s · reaplicadas en %.1f s\n",
        $entries,
        $seedSeconds,
        $applied,
        $rollbackSeconds,
        $migrateSeconds,
    ));
})->group('RF-PD-10', 'RN-01', 'RN-02', 'RL-04', 'RS-07', 'RN-13');
