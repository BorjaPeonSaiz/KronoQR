<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion que acota los umbrales de `compliance_profiles` sabe volver atras
 * (`/migracion-segura`: «una migracion cuyo `down()` no se ha probado no tiene
 * `down()`»).
 *
 * ## Por que importa
 *
 * Es un paso **expand** puro —dos `CHECK` nuevos, nada que se renombre ni se
 * borre—, y aun asi hay que probar la vuelta: si `down()` no soltara las
 * restricciones, una instalacion que revirtiera una actualizacion se quedaria con
 * limites que la version anterior no conoce, y el primer intento de escribir un
 * perfil por encima de ellos saldria como un error de base de datos sin
 * explicacion.
 *
 * ## Como se ejecuta
 *
 * Con `migrate:rollback` y `migrate` sobre la conexion de MIGRACION, que es lo
 * que hace `update.sh` cuando hay que regresar. No se invoca `up()`/`down()` a
 * mano: eso probaria el codigo y no el camino por el que se ejecuta de verdad. Y
 * las lecturas van por esa misma conexion, para no pelear por el bloqueo con la
 * transaccion abierta de `RefreshDatabase`.
 */

uses(RefreshDatabase::class);

it('revierte y vuelve a aplicar los topes de los umbrales', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    expect(boundsMigrationConstraints($migrator))
        ->toContain('compliance_profiles_chk_threshold_bounds', 'compliance_profiles_chk_weekly_covers_daily');

    try {
        // CUANTOS pasos, y no «uno»: cada migracion nueva que se añada despues
        // desplaza a esta, y un `--step=1` fijo acabaria probando el `down()` de
        // otra sin que nadie lo notara.
        $steps = boundsMigrationStepsBack($migrator, '2026_09_06_100000_bound_compliance_profile_thresholds');

        expect($steps)->toBeGreaterThan(0);

        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0);

        $constraints = boundsMigrationConstraints($migrator);

        // Las dos se sueltan y el esquema queda como lo dejo la tarea 1.3: los
        // positivos y el rango del dia de la semana, que no son de esta
        // migracion y no pueden desaparecer con ella.
        expect($constraints)->not->toContain('compliance_profiles_chk_threshold_bounds')
            ->and($constraints)->not->toContain('compliance_profiles_chk_weekly_covers_daily')
            ->and($constraints)->toContain(
                'compliance_profiles_chk_positive_thresholds',
                'compliance_profiles_chk_week_starts_on',
            );

        // Y el perfil sigue intacto: es un paso expand, no toca ni un dato.
        expect($migrator->table('compliance_profiles')->where('name', 'ES-hosteleria')->value('min_rest_hours'))
            ->toBe(12);

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: si el `migrate` de arriba ya corrio, este no hace nada.
        // La base de pruebas es compartida y dejarla sin los topes convertiria un
        // fallo de asercion en un fallo en cascada de todo lo que venga despues.
        Commands::run('migrate --database='.$name);
    }

    $constraints = boundsMigrationConstraints($migrator);

    expect($constraints)->toContain(
        'compliance_profiles_chk_threshold_bounds',
        'compliance_profiles_chk_weekly_covers_daily',
    );

    // Y quedan VALIDADAS, no solo declaradas: un `NOT VALID` que nadie valida
    // deja pasar las filas que ya existian, que es justo lo que hay que impedir.
    expect(boundsMigrationUnvalidated($migrator))->toBe([]);
})->group('RF-PD-07');

/**
 * @return list<string>
 */
function boundsMigrationConstraints(ConnectionInterface $connection): array
{
    /** @var list<string> $names */
    $names = $connection->table('pg_constraint')
        ->where('conrelid', DB::raw("'compliance_profiles'::regclass"))
        ->pluck('conname')
        ->all();

    return $names;
}

/**
 * @return list<string>
 */
function boundsMigrationUnvalidated(ConnectionInterface $connection): array
{
    /** @var list<string> $names */
    $names = $connection->table('pg_constraint')
        ->where('conrelid', DB::raw("'compliance_profiles'::regclass"))
        ->where('convalidated', false)
        ->pluck('conname')
        ->all();

    return $names;
}

/**
 * Cuantos pasos de `migrate:rollback` hacen falta para deshacer la migracion
 * indicada, contando desde la ultima aplicada.
 *
 * Mismo motivo que su gemela en la prueba de la contraccion de la 5.1: con
 * `--step=1` escrito a mano, la siguiente tarea que traiga una migracion haria
 * que esto revirtiera la suya y diera por probado un `down()` que no es el que
 * se quiere comprobar. Se duplica a proposito en vez de compartirse: una funcion
 * global de Pest definida en otro fichero solo existe si ese fichero se ha
 * cargado, y estas pruebas tienen que poder ejecutarse sueltas.
 */
function boundsMigrationStepsBack(ConnectionInterface $connection, string $migration): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === $migration) {
            return $steps;
        }
    }

    return 0;
}
