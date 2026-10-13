<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las dos claves de `incidents` hacia `users` pasan de `ON DELETE SET NULL` a
 * `RESTRICT` (ADR-057 §2, bloque 7 de la 2.2.1; revision del arquitecto H1 y del
 * revisor; RL-04, RN-13).
 *
 * ## Por que
 *
 * `assigned_to_user_id` y `resolved_by_user_id` nacieron `SET NULL`
 * (`2026_09_01_100000_create_incidents_table`): borrar una cuenta de gestion
 * dejaba sus incidencias sin asignar y sin autor de la resolucion. Desde
 * `2026_10_14_100000`, `incidents_guard_transition` rechaza cualquier cambio de
 * esas dos columnas fuera de la resolucion, y la accion referencial `SET NULL`
 * **es un `UPDATE`**: el borrado de la cuenta fallaba con un `42501` que habla
 * de transiciones de incidencias, no de la cuenta. Ademas, quitar quien resolvio
 * una incidencia es justo lo que RN-13 no admite: la traza de una intervencion
 * humana sobre el registro lleva su autor.
 *
 * Con `RESTRICT`, borrar una cuenta que figura en una incidencia falla con
 * `23503` y el nombre de la restriccion, que es el error honesto. Ningun codigo
 * de la aplicacion borra cuentas (se desactivan, ADR-051), asi que nada cambia
 * en el uso normal.
 *
 * ## Patron `/migracion-segura`
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Cada clave se suelta y se vuelve a crear `RESTRICT … NOT VALID` en una transaccion corta; despues, `VALIDATE` fuera de ella. | Ningun codigo depende de la accion referencial. |
 * | **2 / 3** | *No aplica.* | — |
 *
 * PostgreSQL no cambia la accion de una clave ajena en el sitio (`ALTER
 * CONSTRAINT` solo toca la diferibilidad), de ahi el `DROP` + `ADD`. El `ADD …
 * NOT VALID` toma `SHARE ROW EXCLUSIVE` sobre `incidents` y `users` solo lo que
 * tarda en escribir el catalogo (`lock_timeout` de 3 s); el `VALIDATE`, fuera de
 * la transaccion (`validateConstraint()`), toma `SHARE UPDATE EXCLUSIVE`, que no
 * bloquea ni lecturas ni escrituras. Las filas existentes ya cumplian la clave:
 * las columnas y la tabla referida son las mismas.
 *
 * **Idempotente**: si el `VALIDATE` se interrumpe, la migracion no queda
 * anotada y el siguiente `migrate` vuelve a soltar y crear las claves.
 *
 * ## Reversible
 *
 * `down()` las devuelve a `SET NULL` por el mismo camino. Prueba de ida y
 * vuelta: `MigrationsRoundTripTest`; el `23503`, `WorkRecordTransitionsTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * `VALIDATE CONSTRAINT` fuera de la transaccion que creo la clave.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Columna => restriccion. Constantes de esta clase: PostgreSQL no admite
     * parametros enlazados en un identificador.
     *
     * @var array<string, string>
     */
    private const array KEYS = [
        'assigned_to_user_id' => 'incidents_assigned_to_user_id_foreign',
        'resolved_by_user_id' => 'incidents_resolved_by_user_id_foreign',
    ];

    public function up(): void
    {
        $this->replaceKeys('RESTRICT');
    }

    public function down(): void
    {
        $this->replaceKeys('SET NULL');
    }

    private function replaceKeys(string $onDelete): void
    {
        DB::transaction(function () use ($onDelete): void {
            $this->limitLockWait();

            foreach (self::KEYS as $column => $constraint) {
                DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS '.$constraint);
                DB::statement(
                    'ALTER TABLE incidents ADD CONSTRAINT '.$constraint
                    .' FOREIGN KEY ('.$column.') REFERENCES users (id) ON DELETE '.$onDelete.' NOT VALID'
                );
            }
        });

        foreach (self::KEYS as $constraint) {
            $this->validateConstraint('incidents', $constraint);
        }
    }
};
