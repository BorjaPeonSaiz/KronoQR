<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * Las dos migraciones de las cuentas de gestion (**RF-ID-10**, ADR-051) saben
 * volver atras sin perder filas: `users.temporary_password_expires_at` y el
 * ambito `accounts:*` de `admin`.
 *
 * Como `PinLengthMigrationTest`: con el rol de migracion, que es el dueño de las
 * tablas (regla dura 6: el de la aplicacion no tiene DDL), y dentro de una
 * transaccion propia que se deshace al final —en PostgreSQL el DDL es
 * transaccional—, asi que el esquema compartido no cambia para nadie mas.
 */

uses(RefreshDatabase::class);

const MANAGEMENT_ACCOUNT_MIGRATIONS_TEMPORARY = '2026_10_07_100000_add_temporary_password_to_users_table.php';

const MANAGEMENT_ACCOUNT_MIGRATIONS_ABILITY = '2026_10_07_100100_grant_accounts_ability.php';

function migracionDeCuentas(string $fichero): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/'.$fichero);

    return $migration;
}

/**
 * Ejecuta la prueba con la conexion de migracion por defecto y en una
 * transaccion que se deshace siempre.
 */
function enTransaccionDeMigracion(Closure $prueba): void
{
    $previa = DB::getDefaultConnection();
    DB::setDefaultConnection(config()->string('database.migrations.connection'));
    DB::beginTransaction();

    try {
        $prueba();
    } finally {
        DB::rollBack();
        DB::setDefaultConnection($previa);
    }
}

function admitePermisoDeCuentas(): bool
{
    return DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', UserRole::ADMIN->value)
        ->where('permissions.name', 'accounts:*')
        ->exists();
}

it('retira la caducidad de la contrasena temporal sin perder ninguna cuenta y la vuelve a poner vacia', function (): void {
    enTransaccionDeMigracion(function (): void {
        $migration = migracionDeCuentas(MANAGEMENT_ACCOUNT_MIGRATIONS_TEMPORARY);
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));

        $propia = ManagementUsers::withRole(UserRole::ADMIN);
        $temporal = ManagementUsers::withRole(UserRole::RRHH);
        DB::table('users')->where('id', $temporal->id)->update(['temporary_password_expires_at' => '2026-10-10T09:00:00Z']);
        $cuentas = DB::table('users')->orderBy('id')->pluck('uuid')->all();

        $migration->down();

        expect(Schema::hasColumn('users', 'temporary_password_expires_at'))->toBeFalse()
            ->and(DB::table('users')->orderBy('id')->pluck('uuid')->all())->toBe($cuentas);

        $migration->up();

        expect(Schema::hasColumn('users', 'temporary_password_expires_at'))->toBeTrue()
            ->and(DB::table('users')->orderBy('id')->pluck('uuid')->all())->toBe($cuentas)
            // Revertir deja la contrasena como propia: el estado anterior a la
            // 2.2.0, sin inventar una caducidad.
            ->and(DB::table('users')->whereIn('id', [$propia->id, $temporal->id])->whereNotNull('temporary_password_expires_at')->count())->toBe(0);
    });
})->group('RF-ID-10', 'RL-04');

it('retira accounts:* de admin y solo eso, y lo vuelve a conceder una sola vez', function (): void {
    enTransaccionDeMigracion(function (): void {
        $migration = migracionDeCuentas(MANAGEMENT_ACCOUNT_MIGRATIONS_ABILITY);
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));

        $permisos = DB::table('permissions')->count();
        $concesiones = DB::table('role_has_permissions')->count();
        $admin = ManagementUsers::withRole(UserRole::ADMIN);

        expect(admitePermisoDeCuentas())->toBeTrue();

        $migration->down();

        expect(admitePermisoDeCuentas())->toBeFalse()
            ->and(DB::table('permissions')->where('name', 'accounts:*')->exists())->toBeFalse()
            ->and(DB::table('permissions')->count())->toBe($permisos - 1)
            ->and(DB::table('role_has_permissions')->count())->toBe($concesiones - 1)
            ->and(DB::table('users')->where('id', $admin->id)->exists())->toBeTrue()
            ->and(DB::table('model_has_roles')->where('model_id', $admin->id)->count())->toBe(1);

        $migration->up();
        $migration->up();

        expect(admitePermisoDeCuentas())->toBeTrue()
            ->and(DB::table('permissions')->count())->toBe($permisos)
            ->and(DB::table('role_has_permissions')->count())->toBe($concesiones);
    });
})->group('RF-ID-10', 'RF-ID-02');
