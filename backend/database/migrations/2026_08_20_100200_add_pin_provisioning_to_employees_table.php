<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provision y entrega del PIN (RF-ID-09, tarea 1.13).
 *
 * **Que anade y por que.** La tarea 1.6 creo `employees.pin_hash` y nadie lo
 * rellenaba nunca. Rellenarlo no basta: RF-ID-09 exige registrar **la entrega**
 * —fecha y responsable— «igual que la credencial», y para eso hacen falta las
 * mismas tres marcas que ya tiene `credentials`: cuando se emitio, cuando se
 * entrego y quien lo entrego.
 *
 * **Lo que NO anade es tan importante.** No hay ninguna columna con el PIN, ni
 * en claro ni cifrada, ni siquiera temporal: la unica copia es el hash, y eso es
 * lo que hace que «se muestra una sola vez» sea una garantia y no una promesa
 * del panel. Tampoco hay contador de intentos fallidos: el bloqueo por intentos
 * vive en la cache compartida —igual que el del acceso al panel (RF-ID-01)—
 * porque es un dato efimero por naturaleza y escribirlo en la tabla convertiria
 * cada intento fallido de fichaje en una escritura en la fila del empleado.
 *
 * **Tres invariantes declaradas y no confiadas al codigo** (doc 02 §3.2):
 *
 * 1. Un hash de PIN sin instante de emision —o al reves— es un dato a medias:
 *    el panel no podria decir desde cuando esta pendiente de entregar.
 * 2. Una entrega sin responsable no sirve para lo unico que se le pide: decir
 *    quien la hizo cuando alguien discuta un fichaje.
 * 3. No se puede entregar antes de emitir. Es la misma comprobacion que
 *    `credentials_chk_lifecycle_order` hace en la tarjeta.
 *
 * **Expand / migrate / contract** (doc 02 §10.4). Esto es solo *expand*: anade
 * columnas nullable y restricciones, no renombra ni elimina nada, y no hay fase
 * *contract* asociada porque no se deja de usar nada. Las restricciones se
 * anaden `NOT VALID` y se validan despues: `VALIDATE CONSTRAINT` toma un candado
 * `SHARE UPDATE EXCLUSIVE`, que no bloquea lectura ni escritura, mientras que un
 * `ADD CONSTRAINT` normal toma `ACCESS EXCLUSIVE` durante todo el escaneo de la
 * tabla. Con la plantilla de un hotel la diferencia es de milisegundos; el
 * patron esta escrito asi porque es el que hay que seguir cuando no lo sea.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // 1. Expand. Nullable las tres: la plantilla que ya existe no tiene PIN
        //    y no se le puede inventar uno sin entregarselo a nadie.
        DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS pin_issued_at timestamptz(6)');
        DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS pin_delivered_at timestamptz(6)');
        DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS pin_delivered_by_user_id bigint');

        // La cuenta que entrego no se borra mientras conste que entrego algo:
        // el acuse dejaria de decir quien fue (regla dura 5).
        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_pin_delivered_by_user_id_foreign
                FOREIGN KEY (pin_delivered_by_user_id) REFERENCES users (id)
                ON DELETE RESTRICT
        SQL);

        // 2. Invariantes.
        $this->addValidatedCheck(
            'employees_chk_pin_issue_is_complete',
            '(pin_hash IS NULL) = (pin_issued_at IS NULL)',
        );

        $this->addValidatedCheck(
            'employees_chk_pin_delivery_is_complete',
            '(pin_delivered_at IS NULL) = (pin_delivered_by_user_id IS NULL)',
        );

        $this->addValidatedCheck(
            'employees_chk_pin_delivered_after_issued',
            'pin_delivered_at IS NULL OR (pin_issued_at IS NOT NULL AND pin_delivered_at >= pin_issued_at)',
        );
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Reversible y verificada: se retira exactamente lo que anadio `up()`,
        // en orden inverso. Las restricciones se van con la columna, pero se
        // nombran igualmente para que un `down()` parcial —una `up()` que fallo
        // a mitad— deje la tabla como estaba.
        foreach ([
            'employees_chk_pin_delivered_after_issued',
            'employees_chk_pin_delivery_is_complete',
            'employees_chk_pin_issue_is_complete',
            'employees_pin_delivered_by_user_id_foreign',
        ] as $constraint) {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.$constraint);
        }

        foreach (['pin_delivered_by_user_id', 'pin_delivered_at', 'pin_issued_at'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                DB::statement('ALTER TABLE employees DROP COLUMN '.$column);
            }
        }
    }

    /**
     * `ADD CONSTRAINT ... NOT VALID` y despues `VALIDATE`: la primera sentencia
     * no escanea la tabla y la segunda no bloquea las escrituras.
     */
    private function addValidatedCheck(string $name, string $expression): void
    {
        DB::statement('ALTER TABLE employees ADD CONSTRAINT '.$name.' CHECK ('.$expression.') NOT VALID');
        DB::statement('ALTER TABLE employees VALIDATE CONSTRAINT '.$name);
    }
};
