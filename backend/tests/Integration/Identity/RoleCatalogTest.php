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
    // RF-ID-03, tarea 2.1: el responsable gana `employees:read` y **solo** ese de
    // la familia de plantilla. Es lo que reconcilia el Anexo B del doc 01 —donde
    // `GET /employees` es «manager+»— con el ambito minimo del §7.3: ve a su
    // gente, acotada por `AccessScope`, y no puede modificar a nadie.
    'responsable_departamento' => ['responsable_departamento', [
        'attendance:read', 'attendance:correct', 'incidents:*', 'employees:read',
    ]],
    'rrhh' => ['rrhh', [
        'attendance:read', 'attendance:correct', 'incidents:*',
        'employees:read', 'employees:*', 'credentials:*', 'reports:*',
    ]],
    'auditor' => ['auditor', [
        'attendance:read', 'audit:read', 'reports:legal',
    ]],
    'admin' => ['admin', [
        'attendance:read', 'attendance:correct', 'incidents:*',
        'employees:read', 'employees:*', 'credentials:*', 'reports:*', 'reports:legal',
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
        ->and($kiosk)->not->toContain('employees:read')
        ->and($kiosk)->not->toContain('reports:*')
        ->and($kiosk)->not->toContain('credentials:*');
})->group('RS-04');

it('no concede a nadie el ambito de la sesion pendiente de segundo factor', function (): void {
    // RS-06: `2fa:pending` lo emite el propio acceso y **no cuelga de ningun
    // rol**. Si un rol lo tuviera, cualquier sesion suya alcanzaria los endpoints
    // de `/auth/2fa/*` y podria canjear un reto que nadie ha abierto.
    $granted = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', TokenAbility::TWO_FACTOR_PENDING->value)
        ->count();

    expect($granted)->toBe(0);
})->group('RS-06', 'RF-ID-01');
