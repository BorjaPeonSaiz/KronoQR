<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion que añade `updated_at` y `updated_by_user_id` a
 * `compliance_profiles` sabe volver atras (RF-PD-07, RL-04).
 *
 * ## Que se afirma, mas alla de que las columnas existan
 *
 * Que **nacen a `NULL`**. Es lo que hace que el producto pueda distinguir «este
 * perfil sigue siendo el de serie» de «alguien lo reviso», y rellenarlas con la
 * fecha de la migracion diria que se reviso el convenio el dia que se actualizo
 * el software, que es falso.
 *
 * Y que la vuelta atras **no toca ni un umbral**: es un paso expand, y si su
 * `down()` se llevara datos por delante, una actualizacion revertida dejaria al
 * cliente con un perfil distinto del que tenia.
 */

uses(RefreshDatabase::class);

it('añade las dos columnas a NULL y sabe quitarlas sin tocar los umbrales', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    // Recien instalado: la fila la escribio el producto, no una persona.
    $seeded = $migrator->table('compliance_profiles')->where('is_default', true)->first();

    expect($seeded?->updated_at)->toBeNull()
        ->and($seeded?->updated_by_user_id)->toBeNull();

    try {
        $steps = updateTrackingStepsBack($migrator, '2026_09_07_100000_track_compliance_profile_updates');

        expect($steps)->toBeGreaterThan(0);

        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0)
            ->and(updateTrackingColumns($migrator))->not->toContain('updated_at')
            ->and(updateTrackingColumns($migrator))->not->toContain('updated_by_user_id')
            // Y el perfil sigue entero: un `down()` que se llevara un umbral
            // dejaria al cliente con otro convenio despues de revertir.
            ->and($migrator->table('compliance_profiles')->where('is_default', true)->value('min_rest_hours'))
            ->toBe(12);

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: la base de pruebas es compartida y dejarla sin las
        // columnas convertiria un fallo de asercion en un fallo en cascada.
        Commands::run('migrate --database='.$name);
    }

    expect(updateTrackingColumns($migrator))->toContain('updated_at', 'updated_by_user_id');
})->group('RF-PD-07', 'RL-04');

/**
 * @return list<string>
 */
function updateTrackingColumns(ConnectionInterface $connection): array
{
    /** @var list<string> $columns */
    $columns = $connection->table('information_schema.columns')
        ->where('table_name', 'compliance_profiles')
        ->pluck('column_name')
        ->all();

    return $columns;
}

/**
 * Cuantos pasos de `migrate:rollback` deshacen la migracion indicada. Ver el
 * porque en la prueba de la migracion de topes.
 */
function updateTrackingStepsBack(ConnectionInterface $connection, string $migration): int
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
