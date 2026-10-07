<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El responsable de un departamento desde el panel (RF-ID-10, RF-ID-03,
 * ADR-051 §5): `manager_user_uuid` en `PATCH /api/v1/departments/{id}`.
 *
 * Es un cambio de permisos de dos personas, por eso: solo `admin` con el ambito
 * `accounts:*` (403 a todo lo demas, sin cambiar tampoco el nombre), solo una
 * cuenta activa con rol `responsable_departamento` (un unico 422 para las tres
 * causas), y un `role_assignment.changed` por cada cuenta afectada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Token de sesion de esa cuenta con los ambitos de su rol, con o sin
 * `accounts:*` explicitamente: el ambito se fija al emitir el token, y una
 * sesion abierta antes de existir no lo tiene (ADR-051 §2).
 */
function responsableTokenDe(User $user, bool $conCuentas): string
{
    $abilities = [];

    /** @var Permission $permission */
    foreach ($user->getAllPermissions() as $permission) {
        $abilities[] = $permission->name;
    }

    $abilities = array_values(array_diff($abilities, [TokenAbility::ACCOUNTS_ALL->value]));

    if ($conCuentas) {
        $abilities[] = TokenAbility::ACCOUNTS_ALL->value;
    }

    return $user->createToken('Pruebas', $abilities)->plainTextToken;
}

/**
 * @return array{admin: User, token: string, site: int, department: int, name: string}
 */
function responsableEscenario(): array
{
    $site = WorkforceFixtures::site();
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $department = WorkforceFixtures::department($site);

    /** @var string $name */
    $name = DB::table('departments')->where('id', $department)->value('name');

    return [
        'admin' => $admin,
        'token' => responsableTokenDe($admin, true),
        'site' => $site,
        'department' => $department,
        'name' => $name,
    ];
}

function responsableCuenta(string $nombre = 'Cuenta responsable'): User
{
    $user = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $user->name = $nombre;
    $user->save();

    return $user;
}

/**
 * @return list<array{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: array<string, mixed>, raw: string}>
 */
function responsableAsientos(): array
{
    return array_values(DB::table('audit_log')
        ->where('action', 'role_assignment.changed')
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): array {
            /** @var object{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return [
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'payload' => $payload,
                'raw' => $row->payload,
            ];
        })
        ->all());
}

function responsableIdDe(int $department): mixed
{
    return DB::table('departments')->where('id', $department)->value('manager_user_id');
}

it('asigna un responsable y deja un asiento de concesion', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta('Marta Responsable');

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $cuenta->uuid])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('id', $escenario['department'])
        ->assertJsonPath('manager_user_uuid', $cuenta->uuid)
        ->assertJsonPath('manager_name', 'Marta Responsable');

    expect(responsableIdDe($escenario['department']))->toBe($cuenta->id);

    $asientos = responsableAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_type'])->toBe('user')
        ->and($asientos[0]['actor_id'])->toBe($escenario['admin']->id)
        ->and($asientos[0]['subject_type'])->toBe('user')
        ->and($asientos[0]['subject_id'])->toBe($cuenta->id)
        ->and($asientos[0]['payload'])->toEqual([
            'change' => 'granted',
            'department_id' => $escenario['department'],
            'role' => 'responsable_departamento',
            'user_uuid' => $cuenta->uuid,
        ])
        // Regla dura 21: el uuid, nunca el nombre.
        ->and(str_contains($asientos[0]['raw'], 'Marta'))->toBeFalse();
})->group('RF-ID-10', 'RF-ID-03', 'RL-04');

it('cambia de responsable y deja un asiento por cada cuenta afectada', function (): void {
    $escenario = responsableEscenario();
    $saliente = responsableCuenta();
    $entrante = responsableCuenta();
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $saliente->id]);

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $entrante->uuid])
        ->assertValidResponse(200)
        ->assertJsonPath('manager_user_uuid', $entrante->uuid);

    expect(responsableIdDe($escenario['department']))->toBe($entrante->id);

    $asientos = responsableAsientos();

    expect($asientos)->toHaveCount(2)
        ->and($asientos[0]['subject_id'])->toBe($saliente->id)
        ->and($asientos[0]['payload'])->toMatchArray(['user_uuid' => $saliente->uuid, 'change' => 'revoked', 'department_id' => $escenario['department']])
        ->and($asientos[1]['subject_id'])->toBe($entrante->id)
        ->and($asientos[1]['payload'])->toMatchArray(['user_uuid' => $entrante->uuid, 'change' => 'granted', 'department_id' => $escenario['department']])
        ->and($asientos[0]['actor_id'])->toBe($escenario['admin']->id)
        ->and($asientos[1]['actor_id'])->toBe($escenario['admin']->id);
})->group('RF-ID-10', 'RF-ID-03', 'RL-04');

it('deja el departamento sin responsable con null y deja el asiento de retirada', function (): void {
    $escenario = responsableEscenario();
    $saliente = responsableCuenta();
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $saliente->id]);

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => null])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('manager_user_uuid', null)
        ->assertJsonPath('manager_name', null);

    expect(responsableIdDe($escenario['department']))->toBeNull();

    $asientos = responsableAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['payload'])->toMatchArray(['user_uuid' => $saliente->uuid, 'change' => 'revoked']);
})->group('RF-ID-10', 'RF-ID-03', 'RL-04');

it('no deja asiento si el responsable no cambia', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $cuenta->id]);

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => strtoupper($cuenta->uuid)])
        ->assertValidResponse(200)
        ->assertJsonPath('manager_user_uuid', $cuenta->uuid);

    expect(responsableAsientos())->toBe([]);
})->group('RF-ID-10', 'RL-04');

it('renombra y asigna responsable en la misma peticion', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], [
            'name' => 'Recepcion y conserjeria',
            'manager_user_uuid' => $cuenta->uuid,
        ])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Recepcion y conserjeria')
        ->assertJsonPath('manager_user_uuid', $cuenta->uuid);

    expect(DB::table('audit_log')->where('action', 'department.renamed')->count())->toBe(1)
        ->and(responsableAsientos())->toHaveCount(1);
})->group('RF-ID-10', 'RF-GP-01', 'RL-04');

it('responde el mismo 422 si la cuenta no vale y no cambia nada', function (Closure $cuenta): void {
    $escenario = responsableEscenario();
    $previo = responsableCuenta();
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $previo->id]);

    /** @var string $uuid */
    $uuid = $cuenta();

    $respuesta = Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], [
            'name' => 'No deberia cambiar',
            'manager_user_uuid' => $uuid,
        ])
        ->assertValidResponse(422);

    expect($respuesta->json('errors.manager_user_uuid'))
        ->toBe([__('departments.errors.manager_not_eligible')])
        ->and(responsableIdDe($escenario['department']))->toBe($previo->id)
        ->and(DB::table('departments')->where('id', $escenario['department'])->value('name'))->toBe($escenario['name'])
        ->and(responsableAsientos())->toBe([]);
})->with([
    'no existe' => [fn (): string => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91'],
    'de baja' => [function (): string {
        $cuenta = responsableCuenta();
        $cuenta->is_active = false;
        $cuenta->save();

        return $cuenta->uuid;
    }],
    'otro rol' => [fn (): string => ManagementUsers::withRole(UserRole::RRHH)->uuid],
])->group('RF-ID-10', 'RF-ID-03');

it('responde 403 y no cambia nada a cada rol de gestion que no es admin', function (UserRole $rol): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();
    $token = responsableTokenDe(ManagementUsers::withRole($rol), true);

    Api::as($token)
        ->patch('/api/v1/departments/'.$escenario['department'], [
            'name' => 'No deberia cambiar',
            'manager_user_uuid' => $cuenta->uuid,
        ])
        ->assertValidResponse(403);

    expect(responsableIdDe($escenario['department']))->toBeNull()
        ->and(DB::table('departments')->where('id', $escenario['department'])->value('name'))->toBe($escenario['name'])
        ->and(responsableAsientos())->toBe([]);
})->with([
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable_departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-ID-10', 'RF-ID-03');

it('responde 403 a un admin cuya sesion no tiene accounts:*', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();

    Api::as(responsableTokenDe($escenario['admin'], false))
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $cuenta->uuid])
        ->assertValidResponse(403);

    expect(responsableIdDe($escenario['department']))->toBeNull();
})->group('RF-ID-10');

it('responde 403 a un acceso de soporte con cada alcance', function (SupportScope $alcance): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();

    Api::as(SupportGrants::tokenFor($alcance))
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $cuenta->uuid])
        ->assertStatus(403);

    expect(responsableIdDe($escenario['department']))->toBeNull();
})->with(SupportScope::cases())->group('RF-ID-10', 'RF-PD-11');

it('responde 403 a un acceso de soporte aunque su token lleve los ambitos', function (): void {
    // La policy cierra aunque el ambito abra: un soporte se presenta como
    // `admin`, y `assignManager` lo rechaza aparte.
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();

    Api::as(SupportGrants::tokenWithAbilities([TokenAbility::EMPLOYEES_ALL->value, TokenAbility::ACCOUNTS_ALL->value]))
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $cuenta->uuid])
        ->assertStatus(403);

    expect(responsableIdDe($escenario['department']))->toBeNull()
        ->and(responsableAsientos())->toBe([]);
})->group('RF-ID-10', 'RF-PD-11');

it('responde 403 a un token de quiosco y a uno de portal', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta();
    $empleado = WorkforceFixtures::employee($escenario['site'], $escenario['department']);

    foreach ([ManagementUsers::kioskToken(), PortalLogins::open($empleado)] as $token) {
        Api::as($token)
            ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $cuenta->uuid])
            ->assertStatus(403);
    }

    expect(responsableIdDe($escenario['department']))->toBeNull();
})->group('RF-ID-10', 'RS-04');

it('deja a rrhh renombrar sin el campo y conserva el responsable', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta('Marta Responsable');
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $cuenta->id]);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->patch('/api/v1/departments/'.$escenario['department'], ['name' => 'Recepcion y conserjeria'])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Recepcion y conserjeria')
        ->assertJsonPath('manager_user_uuid', $cuenta->uuid)
        ->assertJsonPath('manager_name', 'Marta Responsable');

    expect(responsableIdDe($escenario['department']))->toBe($cuenta->id)
        ->and(responsableAsientos())->toBe([]);
})->group('RF-ID-10', 'RF-GP-01');

it('responde 422 a un cuerpo vacio', function (): void {
    $escenario = responsableEscenario();

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], [])
        ->assertStatus(422);
})->group('RF-GP-01');

it('devuelve el responsable en el listado y en la ficha, tambien si esta de baja', function (): void {
    $escenario = responsableEscenario();
    $cuenta = responsableCuenta('Marta Responsable');
    $cuenta->is_active = false;
    $cuenta->save();
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $cuenta->id]);
    $sinResponsable = WorkforceFixtures::department($escenario['site'], 'Cocina');
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $listado = Api::as($token)->get('/api/v1/departments')->assertValidResponse(200);

    /** @var list<array{id: int, manager_user_uuid: string|null, manager_name: string|null}> $data */
    $data = $listado->json('data');
    $porId = array_column($data, null, 'id');

    expect($porId[$escenario['department']]['manager_user_uuid'])->toBe($cuenta->uuid)
        ->and($porId[$escenario['department']]['manager_name'])->toBe('Marta Responsable')
        ->and($porId[$sinResponsable]['manager_user_uuid'])->toBeNull()
        ->and($porId[$sinResponsable]['manager_name'])->toBeNull();

    Api::as($token)->get('/api/v1/departments/'.$escenario['department'])
        ->assertValidResponse(200)
        ->assertJsonPath('manager_user_uuid', $cuenta->uuid)
        ->assertJsonPath('manager_name', 'Marta Responsable');
})->group('RF-ID-10', 'RF-ID-03');

it('lista los departamentos con su responsable sin una consulta por departamento', function (): void {
    $escenario = responsableEscenario();

    foreach (['Cocina', 'Pisos', 'Mantenimiento'] as $nombre) {
        $id = WorkforceFixtures::department($escenario['site'], $nombre);
        DB::table('departments')->where('id', $id)->update(['manager_user_id' => responsableCuenta()->id]);
    }

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    Api::as($token)->get('/api/v1/departments')->assertValidResponse(200);

    DB::enableQueryLog();
    Api::as($token)->get('/api/v1/departments')->assertValidResponse(200);

    $consultas = array_filter(
        DB::getQueryLog(),
        static fn (array $consulta): bool => str_contains((string) $consulta['query'], 'from "departments"'),
    );

    DB::disableQueryLog();

    expect($consultas)->toHaveCount(1);
})->group('RF-ID-10');

it('quita el alcance a la cuenta desplazada en su siguiente peticion, sin volver a entrar', function (): void {
    // ADR-051, verificacion 5: el alcance por departamento se resuelve en cada
    // peticion desde `departments.manager_user_id`, no se congela en el token.
    $escenario = responsableEscenario();
    $antiguo = responsableCuenta('Responsable saliente');
    $nuevo = responsableCuenta('Responsable entrante');
    DB::table('departments')->where('id', $escenario['department'])->update(['manager_user_id' => $antiguo->id]);
    $persona = WorkforceFixtures::employee($escenario['site'], $escenario['department']);
    $sesionDelAntiguo = ManagementUsers::tokenFor($antiguo);

    Api::as($sesionDelAntiguo)->get('/api/v1/employees')->assertStatus(200)->assertJsonPath('data.0.uuid', $persona);
    Auth::forgetGuards();

    Api::as($escenario['token'])
        ->patch('/api/v1/departments/'.$escenario['department'], ['manager_user_uuid' => $nuevo->uuid])
        ->assertStatus(200);
    Auth::forgetGuards();

    Api::as($sesionDelAntiguo)->get('/api/v1/employees')->assertValidResponse(200)->assertJsonPath('meta.total', 0);
    Auth::forgetGuards();
    Api::as($sesionDelAntiguo)->get('/api/v1/employees/'.$persona)->assertStatus(403);
    Auth::forgetGuards();
    Api::as(ManagementUsers::tokenFor($nuevo))->get('/api/v1/employees/'.$persona)->assertStatus(200);
})->group('RF-ID-10', 'RF-ID-03', 'RS-05');
