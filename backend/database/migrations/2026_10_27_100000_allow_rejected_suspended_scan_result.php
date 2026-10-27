<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events.result` admite `rejected_suspended` (**RN-25**, doc 01 §5.5).
 *
 * ## Que problema resuelve
 *
 * La tarjeta de una persona **suspendida** se rechazaba en el quiosco y quedaba
 * en `scan_events` como `rejected_revoked`, aunque la suspension no revoca la
 * tarjeta: al levantarla, la persona vuelve a fichar con la misma. El registro
 * decia de la tarjeta algo que no habia ocurrido. Desde esta migracion el
 * rechazo tiene su propio valor. **La respuesta al quiosco no cambia**: el mismo
 * `422` generico y de tiempo constante (RS-03, regla dura 17).
 *
 * ## Dos afirmaciones sobre la fila, en una sola migracion
 *
 * 1. **`scan_events_chk_result`**: un valor mas en el catalogo.
 * 2. **`scan_events_chk_worked_minutes`**: el rechazo **no lleva acumulado**,
 *    como los demas rechazos de credencial. La restriccion es una equivalencia
 *    (`(result IN (...)) = (worked_minutes IS NULL)`): sin tocarla, la fila
 *    —con `worked_minutes` nulo— seria imposible de escribir.
 *
 * `scan_events_chk_rejected_has_no_shift_entry` mira el prefijo `rejected` y ya
 * la cubre: un rechazo de una persona suspendida nunca apunta a un tramo.
 *
 * ## Las filas anteriores no se tocan
 *
 * Los rechazos de personas suspendidas escritos antes de esta version siguen
 * como `rejected_revoked`. `scan_events` es un log inmutable (regla dura 5) y
 * reclasificarlos exigiria saber el estado de la persona en cada instante, que
 * no se guarda.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion. Ninguna fila existente cambia de veredicto: nadie ha escrito todavia el valor nuevo. | La version anterior nunca escribe `rejected_suspended`: no nota nada. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* Ampliar un `CHECK` no deja nada obsoleto. | — |
 *
 * `DROP` + `ADD ... NOT VALID` en una transaccion con el `lock_timeout` de
 * {@see LimitsMigrationLocks}, y `VALIDATE` despues y fuera, con `SHARE UPDATE
 * EXCLUSIVE`, que no bloquea el fichaje. Es el mismo procedimiento que la
 * migracion `allow_discarded_unprocessable_scan_result`.
 *
 * ## `down()` verificado
 *
 * Vuelve a las dos restricciones anteriores. **Falla a proposito** si ya hay
 * rechazos `rejected_suspended` escritos: una restriccion que no describe el
 * contenido de la tabla seria una mentira, y reescribirlos como
 * `rejected_revoked` devolveria al registro el error que esta migracion
 * corrige. Quien revierta con esas filas decide a mano.
 *
 * Se prueba en `tests/Integration/Schema/SuspendedScanResultMigrationTest.php`,
 * ademas del ciclo completo de `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: la validacion va fuera de la transaccion que crea las restricciones. */
    public $withinTransaction = false;

    /**
     * Los valores se escriben literalmente y no se importan de `ScanResult`: una
     * migracion describe el esquema en el momento en que se escribio.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_result');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_result
                    CHECK (result IN (
                        'clock_in', 'clock_out', 'break_start', 'break_end',
                        'rejected_unknown', 'rejected_revoked', 'rejected_debounce', 'rejected_signature',
                        'rejected_out_of_order', 'discarded_unprocessable', 'rejected_suspended'
                    ))
                    NOT VALID
            SQL);

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_worked_minutes');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_worked_minutes
                    CHECK (
                        (result IN (
                            'rejected_unknown', 'rejected_revoked', 'rejected_signature',
                            'rejected_out_of_order', 'discarded_unprocessable', 'rejected_suspended'
                        )) = (worked_minutes IS NULL)
                    )
                    NOT VALID
            SQL);
        });

        $this->validateConstraint('scan_events', 'scan_events_chk_result');
        $this->validateConstraint('scan_events', 'scan_events_chk_worked_minutes');
    }

    public function down(): void
    {
        // Antes de tocar nada: con filas escritas, la reversion se niega y deja
        // las restricciones como estaban. Ver el docblock.
        if (DB::table('scan_events')->where('result', 'rejected_suspended')->exists()) {
            throw new RuntimeException(
                'scan_events tiene rechazos rejected_suspended (RN-25): el log no se reescribe (regla dura 5). '
                .'Revertir esta migracion exige decidir a mano que hacer con esas filas.'
            );
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_worked_minutes');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_worked_minutes
                    CHECK (
                        (result IN (
                            'rejected_unknown', 'rejected_revoked', 'rejected_signature',
                            'rejected_out_of_order', 'discarded_unprocessable'
                        )) = (worked_minutes IS NULL)
                    )
                    NOT VALID
            SQL);

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_result');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_result
                    CHECK (result IN (
                        'clock_in', 'clock_out', 'break_start', 'break_end',
                        'rejected_unknown', 'rejected_revoked', 'rejected_debounce', 'rejected_signature',
                        'rejected_out_of_order', 'discarded_unprocessable'
                    ))
                    NOT VALID
            SQL);
        });

        // Se valida tambien: si una fila se escribio entre la comprobacion de
        // arriba y la transaccion, esto falla y la reversion se detiene.
        $this->validateConstraint('scan_events', 'scan_events_chk_result');
        $this->validateConstraint('scan_events', 'scan_events_chk_worked_minutes');
    }
};
