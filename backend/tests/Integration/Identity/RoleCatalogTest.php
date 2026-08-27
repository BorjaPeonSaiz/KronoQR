<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * El catalogo de RF-ID-02 tal y como queda en la base de datos de una
 * instalacion (migracion `seed_role_and_permission_catalog`).
 *
 * **Por que es una prueba de integracion y no unitaria.** Lo que se comprueba no
 * es una regla, es que la instalacion de un cliente arranca con los seis roles y
 * su reparto de ambitos. Si esto faltara, la primera cuenta de administrador no
 * podria recibir ningun rol y el panel se quedaria sin puerta de entrada, y eso
 * solo se ve ejecutando las migraciones de verdad.
 *
 * **Ata las tres copias del reparto**: el enum `TokenAbility`, la migracion y
 * —en la suite de contrato— el `openapi.yaml`. Tres copias existen por motivos
 * legitimos; que digan lo mismo no puede depender de la buena fe.
 */

uses(RefreshDatabase::class);

it('crea los seis roles del catalogo al migrar', function (): void {
    $roles = DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();

    $expected = array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());
    sort($expected);

    expect($roles)->toBe($expected);
})->group('RF-ID-02');

it('reparte los ambitos del documento 02 §7.3 rol por rol', function (string $role, array $abilities): void {
    $granted = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', $role)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();

    sort($abilities);

    expect($granted)->toBe($abilities);
})->with([
    'responsable_departamento' => ['responsable_departamento', [
        'attendance:read', 'attendance:correct', 'incidents:*',
    ]],
    'rrhh' => ['rrhh', [
        'attendance:read', 'attendance:correct', 'incidents:*',
        'employees:*', 'credentials:*', 'reports:*',
    ]],
    'auditor' => ['auditor', [
        'attendance:read', 'audit:read', 'reports:legal',
    ]],
    'admin' => ['admin', [
        'attendance:read', 'attendance:correct', 'incidents:*',
        'employees:*', 'credentials:*', 'reports:*', 'reports:legal',
        'audit:read', 'settings:*', 'license:*', 'support:*', 'diagnostics:*',
    ]],
    'empleado' => ['empleado', ['self:read']],
    'kiosk' => ['kiosk', ['scan:write', 'roster:read', 'heartbeat:write']],
])->group('RF-ID-02', 'RS-04');

it('no siembra ningun permiso fuera del catalogo de ambitos', function (): void {
    // Si aparece uno que el enum no conoce, o el enum se quedo corto o alguien
    // invento un ambito en la migracion. Las dos cosas son un fallo.
    $catalog = TokenAbility::names();
    $unknown = [];

    /** @var mixed $name */
    foreach (DB::table('permissions')->pluck('name')->all() as $name) {
        $permission = \is_string($name) ? $name : '(no es una cadena)';

        if (! \in_array($permission, $catalog, true)) {
            $unknown[] = $permission;
        }
    }

    expect($unknown)->toBe([]);
})->group('RS-04');

it('mantiene al quiosco lejos de los datos de plantilla', function (): void {
    // RS-04 y §7.3: un token de quiosco comprometido no da acceso a la
    // plantilla. Se comprueba en el catalogo, que es donde se decide.
    $kiosk = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', UserRole::KIOSK->value)
        ->pluck('permissions.name')
        ->all();

    expect($kiosk)->not->toContain('employees:*')
        ->and($kiosk)->not->toContain('reports:*')
        ->and($kiosk)->not->toContain('credentials:*');
})->group('RS-04');
