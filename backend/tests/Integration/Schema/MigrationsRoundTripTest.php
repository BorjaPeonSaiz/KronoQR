<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;

/*
 * Todas las migraciones vuelven atras y se reaplican (RF-PD-10, tarea 5.7).
 *
 * «Una migracion cuyo `down()` no se ha probado no tiene `down()`»
 * (skill /migracion-segura). Aqui se prueban TODOS los `down()` de una vez,
 * en orden inverso y sobre una base con volumen realista (la semilla completa,
 * con los 90 dias del `VolumeSeeder`), y despues todos los `up()` otra vez.
 * Lo que se afirma del esquema resultante es lo que el §9.4 llama invariantes
 * de base de datos: las restricciones de RN-01 y RN-02 estan presentes y
 * VALIDAS, y la cadena de auditoria verifica.
 *
 * ## Por que CommittedDatabase y no RefreshDatabase
 *
 * `RefreshDatabase` envuelve la prueba en una transaccion sobre la conexion de
 * la aplicacion; la semilla escribiria dentro de ella y dejaria bloqueos de
 * fila y de tabla que un `DROP TABLE` del rol de migracion —otra conexion—
 * tendria que esperar para siempre. Aqui todo se confirma, y el trait vacia la
 * base al terminar con el rol propietario. Es el unico uso legitimo de este
 * trait fuera de las pruebas de concurrencia, y esta es la razon.
 *
 * ## El tiempo se anota, no se acota
 *
 * La ficha de la tarea pide «tiempo anotado»: se imprime al final para que
 * quien mire la salida de la suite vea cuanto tarda la migracion completa con
 * volumen. No se convierte en un umbral: dependeria de la maquina y fallaria
 * por razones ajenas al esquema.
 */

uses(CommittedDatabase::class);

it('deshace todas las migraciones y las reaplica sobre volumen realista conservando las invariantes', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $schema = $migrator->getSchemaBuilder();

    $seedStart = microtime(true);
    [$seeded, $seedOutput] = Commands::run('db:seed --force');
    $seedSeconds = microtime(true) - $seedStart;

    expect($seeded)->toBe(0, $seedOutput);

    $entries = $migrator->table('shift_entries')->count();
    $applied = $migrator->table('migrations')->count();

    expect($entries)->toBeGreaterThan(1000)
        ->and($applied)->toBeGreaterThan(0);

    // TODOS los down(), en orden inverso, sobre datos de verdad.
    $rollbackStart = microtime(true);
    [$rolledBack, $rollbackOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$applied);
    $rollbackSeconds = microtime(true) - $rollbackStart;

    expect($rolledBack)->toBe(0, $rollbackOutput)
        ->and($migrator->table('migrations')->count())->toBe(0)
        ->and($schema->hasTable('shift_entries'))->toBeFalse()
        ->and($schema->hasTable('audit_log'))->toBeFalse()
        ->and($schema->hasTable('employees'))->toBeFalse();

    // Y todos los up() otra vez, desde cero.
    $migrateStart = microtime(true);
    [$migrated, $migrateOutput] = Commands::run('migrate --database='.$name);
    $migrateSeconds = microtime(true) - $migrateStart;

    expect($migrated)->toBe(0, $migrateOutput)
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
})->group('RF-PD-10', 'RN-01', 'RN-02', 'RL-04', 'RS-07');
