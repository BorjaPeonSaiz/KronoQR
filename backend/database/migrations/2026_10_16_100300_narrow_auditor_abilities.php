<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El `auditor` solo ve la Inspeccion (**RF-ID-02**, bloque 25 de la 2.2.1,
 * decision del propietario de 13-10-2026).
 *
 * ## Que cambia
 *
 * El rol `auditor` pierde `attendance:read` y `audit:read` y se queda con un solo
 * ambito, `reports:legal`: su funcion en la API es la exportacion para la
 * Inspeccion de Trabajo (`GET /api/v1/reports/legal-export`) y nada mas.
 *
 * - `attendance:read` solo le abria, a la altura del ambito, la presencia, las
 *   jornadas, el resumen de cumplimiento y el catalogo de departamentos; las
 *   policies ya le negaban todo salvo este ultimo, que deja de leer en el mismo
 *   despliegue (`DepartmentPolicy`, `routes/api_v1.php`).
 * - `audit:read` no lo exige hoy ninguna ruta. Retirarlo ahora evita que la
 *   primera que lo use se lo abra al auditor sin que nadie lo decida.
 *
 * Los dos permisos **siguen en el catalogo**: `admin` los conserva y el alcance de
 * soporte `read_only` los concede. Solo se borran dos filas del pivote
 * `role_has_permissions`.
 *
 * ## Los tokens ya emitidos
 *
 * Un token de sesion conserva los ambitos con los que se emitio hasta que caduca
 * (12 h). Por eso la retirada no descansa solo en esta migracion: las policies
 * rechazan ademas al `auditor` por rol en todas las rutas que pierde, y un token
 * antiguo con `attendance:read` recibe `403` igual que uno nuevo. Dos controles
 * (doc 02 §7.3, regla dura 18).
 *
 * ## Sin cache que limpiar
 *
 * Los ambitos del token los calcula `EloquentUserAccounts::abilitiesOf()` con
 * `getAllPermissions()`, que carga `roles.permissions` de la base de datos y no
 * de la cache de `spatie/permission`. Ninguna policy pregunta por permisos con
 * `can()`: preguntan por el rol. Llamar a `forgetCachedPermissions()` desde aqui
 * ataria la migracion a que el almacen de cache este disponible durante
 * `artisan migrate`, sin ganar nada.
 *
 * ## Despliegue
 *
 * Un solo paso, sin `ALTER TABLE` ni bloqueo que medir: dos `DELETE` sobre una
 * tabla de catalogo de decenas de filas. El codigo anterior sigue funcionando con
 * el reparto nuevo —el auditor simplemente deja de alcanzar lo que ya le negaban
 * las policies—, y el nuevo funciona con el reparto anterior porque la policy de
 * departamentos lo rechaza por rol.
 *
 * **`down()` verificado**: repone exactamente los dos pares y ninguno mas, con
 * `insertOrIgnore` para poder repetirse.
 *
 * Las cadenas van escritas literalmente y no importadas del enum `TokenAbility`,
 * por lo mismo que en la migracion del catalogo: una migracion describe el
 * esquema en el momento en que se escribio. Y, como en
 * `2026_08_30_100100_grant_read_ability.php`, el cambio de reparto no deja
 * asiento en `audit_log` desde aqui (otro rol de base de datos y una particion
 * que puede no existir aun): lo responde el registro de migraciones.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string GUARD = 'web';

    private const string ROLE = 'auditor';

    /** @var list<string> */
    private const array REVOKED = ['attendance:read', 'audit:read'];

    public function up(): void
    {
        $this->limitLockWait();

        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')
                ->select('id')
                ->where('name', self::ROLE)
                ->where('guard_name', self::GUARD))
            ->whereIn('permission_id', DB::table('permissions')
                ->select('id')
                ->whereIn('name', self::REVOKED)
                ->where('guard_name', self::GUARD))
            ->delete();
    }

    public function down(): void
    {
        $this->limitLockWait();

        $roleId = $this->idOf('roles', self::ROLE);

        foreach (self::REVOKED as $ability) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $this->idOf('permissions', $ability),
            ]);
        }
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
