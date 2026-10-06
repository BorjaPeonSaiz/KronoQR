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
 * restriccion.
 *
 * ## `down()`
 *
 * Retira restricciones y columna. No pierde nada que no se pueda reconstruir:
 * hasta esta version todos los PIN tenian seis cifras.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS pin_length smallint');

        // Relleno: la plantilla es de cientos de filas, no de millones, y la
        // tabla no esta en el camino del fichaje para escrituras.
        DB::statement('UPDATE employees SET pin_length = 6 WHERE pin_hash IS NOT NULL AND pin_length IS NULL');

        $this->addValidatedCheck('employees_chk_pin_length_admissible', 'pin_length IS NULL OR pin_length IN (6, 8)');
        $this->addValidatedCheck('employees_chk_pin_length_with_hash', '(pin_hash IS NULL) = (pin_length IS NULL)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        foreach (['employees_chk_pin_length_with_hash', 'employees_chk_pin_length_admissible'] as $constraint) {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.$constraint);
        }

        if (Schema::hasColumn('employees', 'pin_length')) {
            DB::statement('ALTER TABLE employees DROP COLUMN pin_length');
        }
    }

    /**
     * `NOT VALID` y despues `VALIDATE`: la primera sentencia no escanea la tabla
     * y la segunda no bloquea las escrituras.
     */
    private function addValidatedCheck(string $name, string $expression): void
    {
        DB::statement('ALTER TABLE employees ADD CONSTRAINT '.$name.' CHECK ('.$expression.') NOT VALID');
        DB::statement('ALTER TABLE employees VALIDATE CONSTRAINT '.$name);
    }
};
