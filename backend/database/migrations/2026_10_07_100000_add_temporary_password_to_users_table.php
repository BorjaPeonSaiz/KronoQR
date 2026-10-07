<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La contrasena temporal de las cuentas de gestion (**RF-ID-10**, ADR-051).
 *
 * `users.temporary_password_expires_at`: **no nulo si y solo si** la contrasena
 * vigente la genero el servidor —un alta o un restablecimiento— y su titular aun
 * no la ha cambiado. Es el instante en que deja de servir para entrar. Un unico
 * dato y no una columna de estado aparte: dos columnas que dicen lo mismo
 * acaban diciendo cosas distintas.
 *
 * ## Expand puro
 *
 * Una columna nullable sin valor por defecto: en PostgreSQL es un cambio de
 * catalogo, sin reescribir la tabla ni bloquearla mas que un instante. Las
 * cuentas existentes quedan con `NULL`, es decir, con contrasena **propia**, que
 * es lo correcto: la fijaron sus titulares o la consola antes de la 2.2.0.
 *
 * `TIMESTAMPTZ(6)` y en UTC, como todo instante del producto (regla dura 3).
 *
 * **`down()` verificado**: retira la columna y nada mas. Revertir deja todas
 * las cuentas como si su contrasena fuera propia, que es el estado anterior.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS temporary_password_expires_at timestamptz(6)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        if (Schema::hasColumn('users', 'temporary_password_expires_at')) {
            DB::statement('ALTER TABLE users DROP COLUMN temporary_password_expires_at');
        }
    }
};
