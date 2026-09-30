<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events.claimed_employee_id` y `scan_events.pin_lockout` (**RN-19**,
 * ADR-043, doc 01 §5.5, hallazgos PIN-06 y SC7-02).
 *
 * ## Que problema resuelve
 *
 * Un fichaje por PIN rechazado —PIN erroneo, no emitido o bloqueo activo, a
 * menudo encolado sin red y rechazado al sincronizar— se escribia con
 * `result = 'rejected_unknown'` y `employee_id` nulo: nadie sabia que era la
 * jornada de alguien. Estas dos columnas anotan **a quien correspondia el
 * codigo** cuando es de una persona que puede fichar, y si el intento abrio o
 * encontro el bloqueo de RS-12. La revision diaria las lee hacia atras y abre
 * `rejected_pin_scan` si nadie lo subsana.
 *
 * **La respuesta al quiosco no cambia** (RS-03): la fila se escribe con la
 * misma sentencia con y sin dueño (`EloquentScanLog::record()`).
 *
 * ## El `CHECK` es RN-19 declarada en el esquema
 *
 * `scan_events_chk_pin_claim`: o las dos columnas son nulas, o las dos tienen
 * valor **y** la fila es un rechazo por PIN sin empleado resuelto (`origin =
 * 'pin_kiosk'`, `result = 'rejected_unknown'`, `employee_id IS NULL`). Es el
 * espejo del invariante de `ScanRecord`: un claim en un escaneo de tarjeta, en
 * un fichaje aceptado o junto a un empleado resuelto seria un estado imposible.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos columnas nulas, FK y `CHECK`. Ninguna fila existente cambia. | La version anterior no las escribe: sus filas cumplen el `CHECK` con las dos nulas. |
 * | **2 (migrate)** | *No aplica.* El pasado no se reconstruye: el dato no existia. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * `ADD COLUMN ... NULL` sin valor por defecto solo toca el catalogo. La FK y el
 * `CHECK` entran `NOT VALID` —sin recorrer la tabla con `ACCESS EXCLUSIVE`— y se
 * validan despues con `SHARE UPDATE EXCLUSIVE`, que no bloquea escrituras:
 * toda fila existente tiene las dos columnas nulas y cumple por construccion.
 * `ON DELETE RESTRICT` porque de `employees` no se borra nada (regla dura 5).
 *
 * **Permisos**: los de `fichaje_app` sobre `scan_events` son por tabla
 * (`INSERT`, `SELECT`), asi que las columnas nuevas quedan cubiertas sin
 * `GRANT` nuevo.
 *
 * ## `down()` verificado, y que puede fallar a proposito
 *
 * Si ya hay alguna fila con `claimed_employee_id`, `down()` se **detiene** con
 * una excepcion: quitar la columna borraria a quien correspondia cada intento,
 * que es el dato que sostiene incidencias ya abiertas (regla dura 5, mismo
 * criterio que `2026_09_18_100200_allow_out_of_order_scan_incident_type`). Sin
 * filas, quita el `CHECK`, la FK y las dos columnas. Se prueba en
 * `tests/Integration/Schema/PinClaimMigrationsTest.php` ademas de
 * `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE scan_events ADD COLUMN claimed_employee_id BIGINT NULL');
        DB::statement('ALTER TABLE scan_events ADD COLUMN pin_lockout BOOLEAN NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_claimed_employee_id_foreign
                FOREIGN KEY (claimed_employee_id) REFERENCES employees (id) ON DELETE RESTRICT
                NOT VALID
        SQL);
        DB::statement('ALTER TABLE scan_events VALIDATE CONSTRAINT scan_events_claimed_employee_id_foreign');

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_pin_claim
                CHECK (
                    (claimed_employee_id IS NULL AND pin_lockout IS NULL)
                    OR (claimed_employee_id IS NOT NULL AND pin_lockout IS NOT NULL
                        AND employee_id IS NULL AND origin = 'pin_kiosk' AND result = 'rejected_unknown')
                )
                NOT VALID
        SQL);
        DB::statement('ALTER TABLE scan_events VALIDATE CONSTRAINT scan_events_chk_pin_claim');
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Se detiene en vez de borrar: ver el docblock (regla dura 5).
        if (DB::table('scan_events')->whereNotNull('claimed_employee_id')->exists()) {
            throw new RuntimeException(
                'scan_events tiene fichajes por PIN con claimed_employee_id (RN-19): revertir borraria a quien '
                .'correspondia cada intento. Nada se ha tocado; decide que hacer con esas filas antes de revertir.'
            );
        }

        DB::statement('ALTER TABLE scan_events DROP CONSTRAINT scan_events_chk_pin_claim');
        DB::statement('ALTER TABLE scan_events DROP CONSTRAINT scan_events_claimed_employee_id_foreign');
        DB::statement('ALTER TABLE scan_events DROP COLUMN pin_lockout');
        DB::statement('ALTER TABLE scan_events DROP COLUMN claimed_employee_id');
    }
};
