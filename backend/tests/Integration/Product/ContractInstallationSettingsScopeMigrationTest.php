<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion de contraccion del ambito de `installation_settings` sabe volver
 * atras (`/migracion-segura`: «una migracion cuyo `down()` no se ha probado no
 * tiene `down()`»).
 *
 * ## Por que importa aqui mas que de costumbre
 *
 * Es la unica migracion del producto que **quita** columnas de una tabla con
 * datos. Si el `down()` no reconstruyera el esquema exacto —el `CHECK`, la clave
 * ajena a `sites` y los dos indices parciales—, una instalacion que tuviera que
 * revertir una actualizacion se quedaria con una tabla que la version anterior
 * no sabe leer, y esa version es la que gobierna los umbrales del fichaje.
 *
 * ## Como se ejecuta
 *
 * Con `migrate:rollback` y `migrate` sobre la conexion de MIGRACION, que es
 * exactamente lo que hace `update.sh` (tarea 5.7) cuando algo falla y hay que
 * regresar. No se invoca `up()`/`down()` a mano: eso probaria el codigo de la
 * migracion y no el camino por el que se ejecuta de verdad.
 *
 * ## TODAS las lecturas van por la conexion de migracion, y no es un detalle
 *
 * La suite corre dentro de una transaccion abierta sobre la conexion de la
 * APLICACION (`RefreshDatabase`). Un simple `SELECT` sobre
 * `installation_settings` por esa via toma un `ACCESS SHARE` que no se suelta
 * hasta el final de la prueba, y el `ALTER TABLE` de la migracion se queda
 * esperando detras hasta agotar el `lock_timeout` de 3 s del trait
 * `LimitsMigrationLocks`. El sintoma es un `55P03` en el `finally`, con la base
 * de pruebas revertida a medias. Leyendo por la conexion de migracion no hay dos
 * transacciones peleando por la misma tabla.
 *
 * **La restauracion va en `finally`.** La base de pruebas es compartida dentro
 * de la ejecucion, y dejarla contraida a medias por un fallo de asercion
 * convertiria un fallo en cascada de todo lo que viniera despues.
 */

uses(RefreshDatabase::class);

it('revierte y vuelve a aplicar la contraccion dejando el esquema como estaba', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    try {
        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step=1');

        expect($exitCode)->toBe(0);

        $columns = migrationTestColumns($migrator);
        $indexes = migrationTestIndexes($migrator);

        // El esquema de la tarea 1.3, entero.
        expect($columns)->toContain('scope', 'scope_id')
            ->and($indexes)->toContain('one_installation_setting_per_key', 'one_site_setting_per_key')
            ->and($indexes)->not->toContain('one_setting_per_key');

        // Y la siembra de la tarea 1.3 vuelve, con su ambito. `down()` la
        // repone porque la version anterior del adaptador **exige** que exista:
        // revertir sin ella dejaria una instalacion incapaz de fichar, que es
        // justo el fallo que un `down()` existe para evitar.
        expect($migrator->table('installation_settings')->where('scope', 'installation')->count())->toBe(4);

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: si el `migrate` de arriba ya corrio, este no hace nada.
        Commands::run('migrate --database='.$name);
    }

    $columns = migrationTestColumns($migrator);
    $indexes = migrationTestIndexes($migrator);

    expect($columns)->not->toContain('scope')
        ->and($columns)->not->toContain('scope_id')
        ->and($indexes)->toContain('one_setting_per_key')
        // Y la tabla vuelve a quedar VACIA: la contraccion retira la siembra que
        // nadie ha tocado, porque desde la tarea 5.1 el valor de serie vive en el
        // catalogo en codigo. Con las filas puestas, `source` mentiria en cuatro
        // claves que nadie configuro.
        ->and($migrator->table('installation_settings')->count())->toBe(0);
})->group('RF-PD-01');

/**
 * @return list<string>
 */
function migrationTestColumns(ConnectionInterface $connection): array
{
    /** @var list<string> $columns */
    $columns = $connection->table('information_schema.columns')
        ->where('table_name', 'installation_settings')
        ->pluck('column_name')
        ->all();

    return $columns;
}

/**
 * @return list<string>
 */
function migrationTestIndexes(ConnectionInterface $connection): array
{
    /** @var list<string> $indexes */
    $indexes = $connection->table('pg_indexes')
        ->where('tablename', 'installation_settings')
        ->pluck('indexname')
        ->all();

    return $indexes;
}
