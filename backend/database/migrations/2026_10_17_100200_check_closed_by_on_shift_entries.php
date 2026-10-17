<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La invariante de `shift_entries.closed_by` en la base (RN-05, ADR-058):
 * `(clocked_out_at IS NULL) = (closed_by IS NULL)` y solo `clock_out` o
 * `break_start`. Tercera de las tres migraciones de la fase *expand*.
 *
 * Se crea `NOT VALID` en una transaccion corta —solo catalogo, y desde ese
 * momento rige para toda fila nueva o cambiada— y se valida despues, fuera de
 * ella, con `LimitsMigrationLocks::validateConstraint()`: la validacion recorre
 * la tabla con `SHARE UPDATE EXCLUSIVE`, que no para las lecturas ni las
 * escrituras del fichaje.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La validacion va fuera de la transaccion que crea la restriccion. */
    public $withinTransaction = false;

    private const string CHECK = 'shift_entries_chk_closed_by';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE shift_entries ADD CONSTRAINT '.self::CHECK.' CHECK ('
                ."(clocked_out_at IS NULL) = (closed_by IS NULL) AND (closed_by IS NULL OR closed_by IN ('clock_out', 'break_start'))"
                .') NOT VALID');
        });

        $this->validateConstraint('shift_entries', self::CHECK);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE shift_entries DROP CONSTRAINT IF EXISTS '.self::CHECK);
        });
    }
};
