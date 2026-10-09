<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * El rol de la aplicacion deja de poder escribir en la tabla `migrations`
 * (V4-SC-1 de la verificacion final de la 2.2.0; regla dura 6, ADR-033,
 * ADR-042).
 *
 * ## El problema
 *
 * La migracion de provision (`2026_08_19_099000`) concede
 * `SELECT, INSERT, UPDATE, DELETE ON ALL TABLES`, y cuando se ejecuta la tabla
 * `migrations` ya existe —la crea `migrate:install` antes de la primera
 * migracion—. Asi, `fichaje_app` podia escribir en el registro de lo que el
 * migrador (`fichaje_migrator`, propietario de todo el esquema) da por aplicado.
 * Con ejecucion de codigo en el runtime, eso decide que ejecuta el migrador en la
 * siguiente actualizacion:
 *
 * - **Borrar una fila.** Si es la de la provision, la siguiente actualizacion la
 *   vuelve a ejecutar, su `GRANT … ON ALL TABLES` devuelve `UPDATE` y `DELETE`
 *   sobre `audit_log`, `verify_privileges` de `update.sh` lo detecta y deshace
 *   restaurando la copia —que conserva la fila borrada—: todas las
 *   actualizaciones fallan para siempre.
 * - **Insertar una fila.** Con el nombre, publico en el repositorio, de una
 *   migracion futura (por ejemplo la que traiga los `REVOKE` de ADR-057 §1), el
 *   migrador la da por aplicada y no la ejecuta nunca. Los privilegios que tenia
 *   que retirar se quedan puestos en silencio.
 *
 * La aplicacion no necesita escribir ahi: solo **lee** la tabla, para que
 * `product:doctor` y el paquete de diagnostico cuenten las migraciones aplicadas
 * y las pendientes (`ServiceInspector::database()`). Por eso se conserva
 * `SELECT` y se retira el resto.
 *
 * ## Por que una migracion nueva y no solo un cambio en la de provision
 *
 * Las instalaciones que ya existen tienen la provision anotada y no la vuelven a
 * ejecutar: solo una migracion nueva les retira el privilegio. La provision se
 * corrige ademas (retira lo mismo al final de su `up()`) para que, si alguien
 * consiguiera que se ejecutase otra vez, no lo devolviera.
 *
 * ## No hay caso «migrador y aplicacion son el mismo rol»
 *
 * La provision se niega a correr con el rol de la aplicacion y esta va detras:
 * si se llega aqui, quien ejecuta es otro rol y el `REVOKE` no le quita nada al
 * propietario de la tabla.
 *
 * ## Reversible
 *
 * `down()` devuelve exactamente lo que habia: `INSERT, UPDATE, DELETE`. No toca
 * filas. `TRUNCATE` no se toca en ningun sentido: la provision nunca lo concedio.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON TABLE '.$this->table().' FROM '.$this->applicationRole());
        // Lo unico que la aplicacion hace con esta tabla, explicito: si un
        // `REVOKE ALL` posterior lo quitara, `product:doctor` dejaria de poder
        // decir si hay migraciones pendientes.
        DB::statement('GRANT SELECT ON TABLE '.$this->table().' TO '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('GRANT INSERT, UPDATE, DELETE ON TABLE '.$this->table().' TO '.$this->applicationRole());
    }

    private function table(): string
    {
        return '"'.AuditLogSchema::assertIdentifier(Config::string('database.migrations.table', 'migrations')).'"';
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
