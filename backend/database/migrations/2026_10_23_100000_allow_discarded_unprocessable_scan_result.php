<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events.result` admite `discarded_unprocessable` (**RN-22**, ADR-061,
 * doc 01 §5.5, tarea 17.9 de la 2.3.0).
 *
 * ## Que problema resuelve
 *
 * Cuando el servidor falla el mismo fichaje durante el umbral de ADR-061, lo
 * **descarta**: escribe una fila en `scan_events` con este resultado, que es la
 * que hace que cualquier reenvio del mismo `scan_id` reciba el mismo `422
 * scan-discarded` sin volver a intentarlo (regla dura 8). Sin esta migracion la
 * fila chocaria contra `scan_events_chk_result` y el descarte fallaria hacia
 * conservar, que es seguro pero deja la cola atascada.
 *
 * ## Tres afirmaciones sobre la fila, en una sola migracion
 *
 * 1. **`scan_events_chk_result`**: un valor mas en el catalogo.
 * 2. **`scan_events_chk_worked_minutes`**: el descarte **no lleva acumulado**,
 *    como los rechazos de verdad. La restriccion es una equivalencia
 *    (`(result IN (...)) = (worked_minutes IS NULL)`): sin tocarla, la fila del
 *    descarte —con `worked_minutes` nulo— seria imposible de escribir.
 * 3. **`scan_events_chk_discarded_has_no_shift_entry`** (nueva): un descarte
 *    **nunca** apunta a un tramo. Es el «el descarte no crea, cierra ni toca un
 *    tramo» de ADR-061 dicho por la base de datos y no solo por el codigo. La
 *    de los rechazos (`scan_events_chk_rejected_has_no_shift_entry`) mira el
 *    prefijo `rejected` y no la cubre.
 *
 * Van juntas porque las tres son condicion para escribir la primera fila, y
 * revertir una sin las otras no tiene sentido.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion. Ninguna fila existente cambia de veredicto: nadie ha escrito todavia el valor nuevo. | La version anterior nunca escribe `discarded_unprocessable`: no nota nada. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* Ampliar un `CHECK` no deja nada obsoleto. | — |
 *
 * ## `DROP` + `ADD ... NOT VALID` en una transaccion, `VALIDATE` fuera
 *
 * `DROP` solo toca el catalogo y `NOT VALID` no recorre la tabla con `ACCESS
 * EXCLUSIVE`; las seis sentencias van en una transaccion con el `lock_timeout`
 * de {@see LimitsMigrationLocks}, asi que el fichaje nunca ve un momento sin
 * restriccion. `VALIDATE` va DESPUES y fuera, con `validateConstraint()`: recorre
 * la tabla con `SHARE UPDATE EXCLUSIVE`, que no bloquea el fichaje, y sin
 * retener el candado de la transaccion anterior. Toda fila existente cumple las
 * tres por construccion.
 *
 * ## `down()` verificado
 *
 * Vuelve a las dos restricciones anteriores y quita la nueva. **Falla a
 * proposito** si ya hay descartes escritos: `scan_events` es un log inmutable
 * (regla dura 5) y una restriccion que no describe el contenido de la tabla
 * seria una mentira. Quien revierta con descartes registrados decide a mano.
 *
 * Se prueba en `tests/Integration/Schema/UnprocessableScanMigrationsTest.php`,
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

            // Idempotente: si un `VALIDATE` se interrumpe, el siguiente
            // `migrate` vuelve a entrar aqui y rehace las tres.
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

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_discarded_has_no_shift_entry');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_discarded_has_no_shift_entry
                    CHECK (result <> 'discarded_unprocessable' OR shift_entry_id IS NULL)
                    NOT VALID
            SQL);
        });

        $this->validateConstraint('scan_events', 'scan_events_chk_result');
        $this->validateConstraint('scan_events', 'scan_events_chk_worked_minutes');
        $this->validateConstraint('scan_events', 'scan_events_chk_discarded_has_no_shift_entry');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_discarded_has_no_shift_entry');

            DB::statement('ALTER TABLE scan_events DROP CONSTRAINT IF EXISTS scan_events_chk_worked_minutes');
            DB::statement(<<<'SQL'
                ALTER TABLE scan_events
                    ADD CONSTRAINT scan_events_chk_worked_minutes
                    CHECK (
                        (result IN (
                            'rejected_unknown', 'rejected_revoked', 'rejected_signature',
                            'rejected_out_of_order'
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
                        'rejected_out_of_order'
                    ))
                    NOT VALID
            SQL);
        });

        // Se valida a proposito: con descartes escritos esto falla y la
        // reversion se detiene. Ver el docblock.
        $this->validateConstraint('scan_events', 'scan_events_chk_result');
        $this->validateConstraint('scan_events', 'scan_events_chk_worked_minutes');
    }
};
