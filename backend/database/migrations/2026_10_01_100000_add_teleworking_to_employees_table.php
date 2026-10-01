<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `employees.teleworking` — la marca informativa de teletrabajo (**RF-GP-01**,
 * doc 01 §5.5, decision comercial de la 2.1.0 y Bloque 9 del plan de la 2.2.0).
 *
 * ## Que es, y sobre todo que no es
 *
 * Un si o no por persona que RRHH marca para saber quien teletrabaja y verlo en
 * el listado. **No cambia como se ficha ni se calcula nada**: ninguna consulta
 * del fichaje, de la proyeccion de `daily_totals`, de las incidencias, de los
 * informes ni de la exportacion para la Inspeccion lee esta columna. Lo fija
 * `TeleworkingIsInformativeTest`.
 *
 * ## `NOT NULL DEFAULT false`, y por que eso no reescribe la tabla
 *
 * Desde PostgreSQL 11, `ADD COLUMN ... DEFAULT <constante>` guarda el valor en
 * el catalogo (`pg_attribute.atthasmissing`) y no toca ninguna fila: las filas
 * existentes lo devuelven al leerse. La sentencia toma `ACCESS EXCLUSIVE` el
 * tiempo de cambiar el catalogo, que son milisegundos, y `lock_timeout` la hace
 * rendirse en vez de encolar detras a los fichajes si alguien tiene la tabla
 * cogida. **Sin nulos** porque no hay un tercer estado que signifique algo:
 * «no consta» y «no teletrabaja» son lo mismo para un dato informativo, y un
 * `NULL` obligaria a cada lector a decidir que pinta.
 *
 * ## Patron `/migracion-segura`: expand puro, en un solo despliegue
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una columna con valor de serie. | La version anterior no la nombra: sus `INSERT` reciben `false` del valor por defecto y sus `UPDATE` no la tocan. La nueva la lee y la escribe. |
 * | **2 (migrate)** | *No aplica.* No hay historico que trasladar: el dato no existia, y rellenarlo con una suposicion seria inventarlo. | — |
 * | **3 (contract)** | *No aplica.* No se retira ni se renombra nada. | — |
 *
 * **Permisos**: los de `fichaje_app` sobre `employees` son por tabla, asi que la
 * columna nueva queda cubierta sin `GRANT` nuevo.
 *
 * ## `down()` verificado, y que se detiene a proposito
 *
 * Si alguna ficha ya esta marcada, `down()` se **niega** a quitar la columna:
 * lo marcado por RRHH desapareceria sin rastro —el asiento de auditoria lleva
 * el nombre del campo tocado, no su valor— y nada se borra (regla dura 5, el
 * mismo criterio que `2026_09_30_120000_add_pin_claim_to_scan_events`). Sin
 * marcas, retira la columna. Lo prueban `TeleworkingMigrationTest` y
 * `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE employees ADD COLUMN teleworking BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Se detiene en vez de borrar: ver el docblock (regla dura 5).
        if (DB::table('employees')->where('teleworking', true)->exists()) {
            throw new RuntimeException(
                'employees tiene fichas marcadas con teletrabajo: revertir borraria esa marca sin rastro. '
                .'Nada se ha tocado; desmarcalas desde el panel (queda asiento) antes de revertir.'
            );
        }

        DB::statement('ALTER TABLE employees DROP COLUMN teleworking');
    }
};
