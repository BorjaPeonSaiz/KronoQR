<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `employees.pin_length`: con cuantas cifras se emitio el PIN vigente de cada
 * persona (RF-ID-09, ADR-050, 2.2.0 bloque 12).
 *
 * **Para que existe.** La longitud del PIN pasa a ser un ajuste de la
 * instalacion (`IDENTITY_PIN_LENGTH`, 6 u 8). Cambiarla no invalida los PIN ya
 * emitidos —la comprobacion compara el hash, no la longitud—, asi que tras pasar
 * a 8 conviven PIN de las dos longitudes hasta que cada uno se restablece y se
 * entrega en mano. Esta columna es lo que permite saber **cuantos** quedan cortos
 * (`product:doctor`). **No sale por la API**: decirle a un responsable que
 * companeros tienen el PIN corto es decirle a quien atacar.
 *
 * ## Expand, y la unica fase
 *
 * Columna nueva y nullable; ningun codigo anterior la lee, asi que no hay
 * contract que hacer despues. Se rellena con `6` para todos los PIN existentes,
 * que son los unicos que el producto ha emitido hasta esta version. Lo escribe
 * en adelante la misma sentencia que escribe `pin_hash` (ADR-046).
 *
 * ## Invariantes en el esquema, no solo en PHP
 *
 * - `pin_length IN (6, 8)`: siete no es una longitud que el producto emita.
 * - `(pin_hash IS NULL) = (pin_length IS NULL)`: un PIN sin longitud o una
 *   longitud sin PIN serian una fila a medio escribir.
 *
 * `update.sh` migra con la instalacion en mantenimiento, asi que ningun proceso
 * del binario anterior escribe `pin_hash` sin `pin_length` entre el relleno y la
 * restriccion. Columna, relleno y `CHECK ... NOT VALID` van en una transaccion;
 * los `VALIDATE`, despues de confirmarla
 * (`LimitsMigrationLocks::validateConstraint()`, hallazgo DB3). La prueba de
 * ida y vuelta vive en `tests/Integration/Schema/PinLengthMigrationRoundTripTest.php`:
 * una migracion no transaccional no se puede ensayar dentro de la transaccion
 * de una prueba.
 *
 * ## `down()`, y que puede fallar a proposito
 *
 * Retira restricciones y columna, **solo si todos los PIN emitidos tienen seis
 * cifras**: es lo que la version anterior da por hecho, asi que en ese caso la
 * columna no guarda nada que no se pueda reconstruir. En cuanto existe un PIN de
 * ocho (ADR-050), quitarla borraria el unico registro de cuales lo son y la
 * version anterior los contaria como de seis; `down()` se **detiene** con una
 * excepcion sin tocar nada (regla dura 5, mismo criterio que
 * `2026_09_30_120000_add_pin_claim_to_scan_events`). La comprobacion va despues
 * de la primera sentencia que bloquea `employees`, para que no entre un PIN de
 * ocho entre ella y el `DROP COLUMN`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los `VALIDATE` van fuera de la transaccion que crea la columna y los
     * `CHECK`: ver {@see LimitsMigrationLocks}.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /** @var array<string, string> Nombre => expresion de cada `CHECK`. */
    private const array CHECKS = [
        'employees_chk_pin_length_admissible' => 'pin_length IS NULL OR pin_length IN (6, 8)',
        'employees_chk_pin_length_with_hash' => '(pin_hash IS NULL) = (pin_length IS NULL)',
    ];

    public function up(): void
    {
        // Idempotente: si un `VALIDATE` se interrumpe, el siguiente `migrate`
        // vuelve a entrar aqui con la columna ya creada y rellena.
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS pin_length smallint');

            // Relleno: la plantilla es de cientos de filas, no de millones, y la
            // tabla no esta en el camino del fichaje para escrituras.
            DB::statement('UPDATE employees SET pin_length = 6 WHERE pin_hash IS NOT NULL AND pin_length IS NULL');

            // `NOT VALID` aqui y `VALIDATE` despues del `COMMIT`: la primera
            // sentencia no escanea la tabla y la segunda no bloquea las escrituras.
            foreach (self::CHECKS as $name => $expression) {
                DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.$name);
                DB::statement('ALTER TABLE employees ADD CONSTRAINT '.$name.' CHECK ('.$expression.') NOT VALID');
            }
        });

        foreach (array_keys(self::CHECKS) as $name) {
            $this->validateConstraint('employees', $name);
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            // El primer `DROP CONSTRAINT` toma `ACCESS EXCLUSIVE` sobre
            // `employees` hasta el `COMMIT`: desde aqui no se emite ningun PIN.
            foreach (array_reverse(array_keys(self::CHECKS)) as $name) {
                DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.$name);
            }

            if (! Schema::hasColumn('employees', 'pin_length')) {
                return;
            }

            // Se comprueba DESPUES del bloqueo: si hay algun PIN que no sea de
            // seis cifras, la excepcion deshace los `DROP CONSTRAINT` y nada se
            // ha tocado (ver el docblock, regla dura 5).
            if (DB::table('employees')->whereNotNull('pin_length')->where('pin_length', '<>', 6)->exists()) {
                throw new RuntimeException(
                    'employees tiene PIN emitidos con una longitud distinta de seis cifras (ADR-050): la version '
                    .'anterior los trataria como de seis y revertir borraria cuales son. Nada se ha tocado; '
                    .'restablece esos PIN con seis cifras antes de revertir.'
                );
            }

            DB::statement('ALTER TABLE employees DROP COLUMN pin_length');
        });
    }
};
