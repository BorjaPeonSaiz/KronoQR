<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un centro de trabajo por instalacion (ADR-040).
 *
 * Un indice unico sobre una expresion constante admite como mucho una fila:
 * el segundo `INSERT` en `sites` falla con `sites_single_row_uidx`, y el caso
 * de uso lo traduce a `SiteAlreadyConfigured`. Es una restriccion declarativa,
 * como el resto de invariantes del esquema (doc 02 §3.2), y no un `if`.
 *
 * `site_id` se conserva en todas las tablas que lo tienen: el registro legal
 * (`shift_entries`) y la cadena de auditoria no se tocan.
 *
 * **Se niega a migrar una base de datos con mas de un centro.** No hay ninguna
 * instalacion desplegada con varios (ADR-040), asi que solo puede ocurrir en un
 * entorno de desarrollo sembrado con la semilla anterior. Borrar los sobrantes
 * desde aqui contradiria la regla dura 5 y, ademas, arrastraria `employees`,
 * `devices` y `shift_entries` que cuelgan de ellos: la salida es `make clean`
 * y volver a sembrar.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        $sites = DB::table('sites')->count();

        if ($sites > 1) {
            throw new RuntimeException(sprintf(
                'Hay %d centros en `sites` y el producto admite uno por instalacion (ADR-040). '
                .'En desarrollo: `make clean` y vuelve a sembrar. En cualquier otro entorno: para y revisa.',
                $sites,
            ));
        }

        DB::statement('CREATE UNIQUE INDEX sites_single_row_uidx ON sites ((true))');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP INDEX IF EXISTS sites_single_row_uidx');
    }
};
