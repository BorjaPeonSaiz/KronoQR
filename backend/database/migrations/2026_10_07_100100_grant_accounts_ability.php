<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El ambito `accounts:*` y su reparto: **solo `admin`** (**RF-ID-10**, doc 02
 * §7.3 nota 7, ADR-051).
 *
 * ## Por que un ambito propio y no `settings:*`
 *
 * Un acceso de soporte con alcance `configuration` lleva `settings:*`, y crear
 * una cuenta `admin` es como un acceso temporal se vuelve permanente. Se
 * quieren dos controles —este ambito y la policy que rechaza a todo actor de
 * soporte— y no uno.
 *
 * ## Expand puro
 *
 * Inserta una fila en `permissions` y una en `role_has_permissions`, sin `ALTER
 * TABLE`. `password:change` **no** se siembra: como `2fa:pending`, lo emite el
 * acceso y no cuelga de ningun rol.
 *
 * **Los tokens ya emitidos no llevan el ambito**: las abilities se fijan al
 * emitir. Tras actualizar, un `admin` ve «Cuentas» al volver a entrar (la sesion
 * dura como mucho doce horas). Va en las notas de la version.
 *
 * **`down()` verificado**: borra el pivote y la fila de `permissions` que esta
 * migracion creo, y ninguna otra.
 *
 * **Las cadenas van escritas literalmente** y no importadas de `TokenAbility`:
 * una migracion describe el esquema del momento en que se escribio.
 *
 * **Sin asiento `permission.changed`**, por las mismas razones de sitio que
 * `2026_08_30_100100_grant_read_ability` (rol de base de datos de migracion,
 * particion de `audit_log` no garantizada): deuda anotada alli.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string GUARD = 'web';

    private const string ABILITY = 'accounts:*';

    private const string ROLE = 'admin';

    public function up(): void
    {
        $this->limitLockWait();

        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => self::ABILITY,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_has_permissions')->insertOrIgnore([
            'role_id' => $this->idOf('roles', self::ROLE),
            'permission_id' => $this->idOf('permissions', self::ABILITY),
        ]);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')
                ->select('id')
                ->where('name', self::ABILITY)
                ->where('guard_name', self::GUARD))
            ->delete();

        DB::table('permissions')
            ->where('name', self::ABILITY)
            ->where('guard_name', self::GUARD)
            ->delete();
    }

    private function idOf(string $table, string $name): int
    {
        /** @var object{id: int}|null $row */
        $row = DB::table($table)
            ->select('id')
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->first();

        if ($row === null) {
            throw new RuntimeException('Falta «'.$name.'» en '.$table.': el catalogo de RF-ID-02 no esta sembrado.');
        }

        return $row->id;
    }
};
