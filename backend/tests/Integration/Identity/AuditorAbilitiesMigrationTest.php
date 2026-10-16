<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion que deja al `auditor` solo con `reports:legal` (**RF-ID-02**,
 * bloque 25 de la 2.2.1) sabe ir y volver sin tocar ninguna otra concesion.
 *
 * Como `ManagementAccountMigrationsTest`: con el rol de migracion, dueño de las
 * tablas, y dentro de una transaccion que se deshace siempre, asi que el
 * catalogo compartido no cambia para nadie mas.
 */

uses(RefreshDatabase::class);

const AUDITOR_ABILITIES_MIGRATION_FILE = '2026_10_16_100300_narrow_auditor_abilities.php';

/** @return list<string> */
function auditorAbilitiesMigrationGranted(): array
{
    /** @var list<string> $granted */
    $granted = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', UserRole::AUDITOR->value)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();

    return $granted;
}

function auditorAbilitiesMigrationRun(Closure $prueba): void
{
    $previa = DB::getDefaultConnection();
    DB::setDefaultConnection(config()->string('database.migrations.connection'));
    DB::beginTransaction();

    try {
        /** @var Migration $migration */
        $migration = require database_path('migrations/'.AUDITOR_ABILITIES_MIGRATION_FILE);
        $prueba($migration);
    } finally {
        DB::rollBack();
        DB::setDefaultConnection($previa);
    }
}

it('deja al auditor solo con reports:legal', function (): void {
    expect(auditorAbilitiesMigrationGranted())->toBe(['reports:legal']);
})->group('RF-ID-02', 'RQ-07');

it('repone attendance:read y audit:read al auditor al revertir, y solo eso', function (): void {
    auditorAbilitiesMigrationRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));

        $permisos = DB::table('permissions')->count();
        $concesiones = DB::table('role_has_permissions')->count();

        $migration->down();
        $migration->down();

        expect(auditorAbilitiesMigrationGranted())->toBe(['attendance:read', 'audit:read', 'reports:legal'])
            ->and(DB::table('permissions')->count())->toBe($permisos)
            ->and(DB::table('role_has_permissions')->count())->toBe($concesiones + 2);

        $migration->up();
        $migration->up();

        expect(auditorAbilitiesMigrationGranted())->toBe(['reports:legal'])
            ->and(DB::table('permissions')->count())->toBe($permisos)
            ->and(DB::table('role_has_permissions')->count())->toBe($concesiones)
            // `admin` conserva los dos ambitos: la migracion solo toca al auditor.
            ->and(DB::table('role_has_permissions')
                ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('roles.name', UserRole::ADMIN->value)
                ->whereIn('permissions.name', ['attendance:read', 'audit:read'])
                ->count())->toBe(2);
    });
})->group('RF-ID-02', 'RS-04');
