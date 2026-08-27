<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `credentials.uuid` — identificador **publico** de la credencial (tarea 1.5).
 *
 * **Por que hace falta.** El Anexo B del doc 01 y el contrato OpenAPI —autoridad
 * 2 de `CLAUDE.md`— nombran la credencial por UUID en la ruta:
 * `POST /api/v1/credentials/{uuid}/revoke`. La tabla nacio en la tarea 1.3 con
 * `id` autoincremental y sin identificador publico, asi que el contrato no se
 * podia satisfacer sin exponer la clave interna. Exponerla seria doble error:
 * revela cuantas credenciales se han emitido y en que orden —lo mismo que
 * ADR-005 prohibe en el propio QR— y ata la URL a un detalle de persistencia.
 *
 * Es la misma decision que ya tomaron `employees.uuid`, `users.uuid` y
 * `devices.uuid`: el BIGINT no sale nunca de la base de datos.
 *
 * **Expand / migrate / contract** (doc 02 §10.4). Esta migracion es solo la fase
 * *expand*: **anade** una columna y un indice y no renombra ni elimina nada. La
 * columna nace `NULL`, se rellena y solo despues pasa a `NOT NULL`, que es el
 * orden que permite aplicarla sobre una tabla con filas de un cliente ya en
 * produccion. No hay fase *contract* asociada: no se deja de usar nada.
 *
 * **Sin `CREATE INDEX CONCURRENTLY`, y es deliberado.** La tarea 1.5 es la que
 * *emite* la primera credencial del producto: cuando esta migracion corre, la
 * tabla esta vacia en toda instalacion existente y el indice es instantaneo.
 * Usar `CONCURRENTLY` obligaria a `$withinTransaction = false` y a renunciar a
 * la atomicidad —si falla, el indice queda `INVALID` y hay que borrarlo a
 * mano— a cambio de nada. El razonamiento esta escrito en
 * {@see LimitsMigrationLocks}.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // 1. Expand: columna nueva y nullable. No bloquea lectura ni escritura.
        DB::statement('ALTER TABLE credentials ADD COLUMN IF NOT EXISTS uuid uuid');

        // 2. Relleno. `gen_random_uuid()` es de pgcrypto, que la migracion de
        //    extensiones ya habilita. Es UUID v4 y no v7: aqui el identificador
        //    solo tiene que ser opaco e irrepetible, y v4 no filtra el instante
        //    de emision. Las credenciales que emita la aplicacion llevaran el
        //    UUID que genere PHP.
        DB::statement('UPDATE credentials SET uuid = gen_random_uuid() WHERE uuid IS NULL');

        // 3. Ya no puede faltar.
        DB::statement('ALTER TABLE credentials ALTER COLUMN uuid SET NOT NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS credentials_uuid_unique ON credentials (uuid)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Reversible y verificada: se retiran el indice y la columna en el orden
        // inverso al que se crearon. `down()` de una fase *expand* si puede
        // eliminar, porque lo que elimina es lo que ella misma anadio.
        DB::statement('DROP INDEX IF EXISTS credentials_uuid_unique');

        if (Schema::hasColumn('credentials', 'uuid')) {
            DB::statement('ALTER TABLE credentials DROP COLUMN uuid');
        }
    }
};
