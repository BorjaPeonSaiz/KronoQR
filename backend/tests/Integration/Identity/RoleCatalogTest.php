<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Architecture\Support\Repo;
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
    // RF-ID-02, bloque 25 de la 2.2.1: el auditor solo descarga la exportacion
    // para la Inspeccion. Pierde `attendance:read` y `audit:read`
    // (migracion `narrow_auditor_abilities`).
    'auditor' => ['auditor', ['reports:legal']],
    'admin' => ['admin', [
        'attendance:read', 'attendance:correct', 'incidents:*',
        'employees:read', 'employees:*', 'credentials:*', 'reports:*', 'reports:legal',
        'audit:read', 'settings:*', 'license:*', 'support:*', 'diagnostics:*',
        // RF-ID-10: las cuentas de gestion, solo `admin`.
        'accounts:*',
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

it('no concede a nadie el ambito de la sesion de contrasena temporal', function (): void {
    // RF-ID-10, ADR-051: `password:change` lo emite el acceso a una cuenta con
    // contrasena temporal. Si un rol lo tuviera, toda sesion de ese rol pareceria
    // pendiente de cambiar su contrasena.
    $granted = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', TokenAbility::PASSWORD_CHANGE->value)
        ->count();

    expect($granted)->toBe(0);
})->group('RF-ID-10');

it('concede accounts:* solo a admin', function (): void {
    // RF-ID-10, doc 02 §7.3 nota 7: ni `rrhh`, ni el auditor, ni ningun alcance
    // de soporte (que no son roles) administran cuentas.
    $roles = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', TokenAbility::ACCOUNTS_ALL->value)
        ->pluck('roles.name')
        ->all();

    expect($roles)->toBe(['admin']);
})->group('RF-ID-10', 'RF-ID-02');

it('concede a cada rol de gestion exactamente lo que dice docs/api/role-abilities.json', function (): void {
    /*
     * El panel ata su menu, sus rutas y sus fixtures a ese fichero
     * (`frontend-admin/tests/unit/roleCatalog.spec.ts`, 2.3.0, tarea 14.7). Si
     * una migracion cambia un rol y el fichero no, el panel enseñaria entradas
     * que el servidor deniega, o esconderia las que concede. Esta prueba es la
     * mitad del servidor de ese contrato: la tabla real tras migrar, rol por rol.
     */
    $catalog = json_decode(Repo::contents('docs/api/role-abilities.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($catalog)->toBeArray()->toHaveKey('roles');

    /** @var array<string, list<string>> $declared */
    $declared = \is_array($catalog) && \is_array($catalog['roles'] ?? null) ? $catalog['roles'] : [];

    $management = array_map(static fn (UserRole $role): string => $role->value, UserRole::managementRoles());
    $keys = array_keys($declared);
    sort($management);
    sort($keys);

    expect($keys)->toBe($management, 'docs/api/role-abilities.json no lista exactamente los roles de gestion.');

    foreach ($declared as $role => $abilities) {
        $granted = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)
            ->where('roles.guard_name', 'web')
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();

        sort($abilities);

        expect($granted)->toBe($abilities, 'El rol '.$role.' no tiene en la base los ambitos que declara docs/api/role-abilities.json.');
    }
})->group('RF-ID-02', 'RF-ID-03');
