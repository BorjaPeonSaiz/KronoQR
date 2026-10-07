<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Quien LEE el catalogo de departamentos (bloque 21 de la 2.2.0, R6-BD-01 y
 * R4-QA-03; decision del propietario de 02-10-2026).
 *
 * `GET /api/v1/departments` y `GET /api/v1/departments/{id}` los leen los cuatro
 * roles de gestion —`admin`, `rrhh`, `responsable_departamento` y `auditor`— y
 * **sin acotar por departamento**: es un catalogo, no datos de personas, y el
 * alcance de RF-ID-03 se aplica sobre las personas. Crear y renombrar siguen
 * siendo de `admin` y `rrhh` (regla dura 18).
 *
 * Los dos controles del §7.3 se ejercitan por separado: el AMBITO
 * (`employees:read` o `attendance:read`; el auditor solo lleva el segundo) y la
 * POLICY (`DepartmentPolicy`, que ademas cierra la lectura a todo acceso de
 * soporte aunque su ambito la alcance).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // Ninguno de los cuatro roles debe depender aqui del valor de serie de RS-06:
    // los tokens se emiten ya completos y lo que se prueba es la autorizacion.
    config()->set('identity.two_factor.required_roles', []);
});

/**
 * Dos departamentos, uno dirigido por `$managerUserId` y otro sin responsable.
 *
 * @return array{0: int, 1: int}
 */
function departamentosDelCatalogo(?int $managerUserId = null): array
{
    $site = WorkforceFixtures::site();
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    // El ayudante sufija el nombre para no chocar con el UNIQUE por centro; aqui
    // se fija para poder afirmar sobre el.
    Department::query()->whereKey($cocina)->update(['name' => 'Cocina']);
    Department::query()->whereKey($recepcion)->update(['name' => 'Recepcion']);

    if ($managerUserId !== null) {
        Department::query()->whereKey($cocina)->update(['manager_user_id' => $managerUserId]);
    }

    return [$cocina, $recepcion];
}

it('deja leer el listado y el detalle a los cuatro roles de gestion', function (UserRole $role): void {
    // arrange
    [$cocina] = departamentosDelCatalogo();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    // act / assert
    Api::as($token)->get('/api/v1/departments')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data');

    Api::as($token)->get('/api/v1/departments/'.$cocina)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('id', $cocina)
        ->assertJsonPath('name', 'Cocina');
})->with([
    'admin' => UserRole::ADMIN,
    'rrhh' => UserRole::RRHH,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
    'auditor' => UserRole::AUDITOR,
])->group('RF-ID-03', 'RF-GP-01', 'RQ-06', 'RQ-07');

it('ensena al responsable el catalogo entero, tambien los departamentos que no dirige', function (): void {
    // Es un catalogo, no datos de personas: el alcance de RF-ID-03 no se aplica
    // aqui. Y ve quien dirige cada uno, que es para lo que viaja `manager_name`.

    // arrange
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    [$cocina, $recepcion] = departamentosDelCatalogo($jefeDeCocina->id);
    $token = ManagementUsers::tokenFor($jefeDeCocina);

    // act
    $listado = Api::as($token)->get('/api/v1/departments');

    // assert
    $listado->assertValidResponse(200);

    /** @var list<array{id: int, manager_user_uuid: string|null}> $data */
    $data = $listado->json('data');
    $porId = array_column($data, 'manager_user_uuid', 'id');

    expect(array_keys($porId))->toEqualCanonicalizing([$cocina, $recepcion])
        ->and($porId[$cocina])->toBe($jefeDeCocina->uuid)
        ->and($porId[$recepcion])->toBeNull();

    // Y el detalle de uno que no dirige, sin `403` ni asiento de denegacion.
    Api::as($token)->get('/api/v1/departments/'.$recepcion)
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Recepcion');

    expect(DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->count())->toBe(0);
})->group('RF-ID-03', 'RF-GP-01', 'RQ-06');

it('no deja crear ni renombrar al responsable ni al auditor', function (UserRole $role, string $method, string $uri, array $body): void {
    // arrange
    [$cocina] = departamentosDelCatalogo();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    // act / assert
    Api::as($token)
        ->call($method, str_replace('{id}', (string) $cocina, $uri), $body)
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    // Nada cambia: ni un departamento de mas ni un nombre distinto.
    expect(DB::table('departments')->count())->toBe(2)
        ->and(DB::table('departments')->where('id', $cocina)->value('name'))->toBe('Cocina');
})->with([
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
    'auditor' => UserRole::AUDITOR,
])->with([
    'crear' => ['POST', '/api/v1/departments', ['name' => 'Lavanderia']],
    'renombrar' => ['PATCH', '/api/v1/departments/{id}', ['name' => 'Cocina central']],
])->group('RF-ID-03', 'RF-GP-01', 'RQ-07', 'RS-05');

it('no deja leer el catalogo a ningun acceso de soporte', function (SupportScope $scope): void {
    // `read_only` lleva `employees:read` y `attendance:read`, asi que su AMBITO
    // alcanza la ruta: el `403` lo pone `DepartmentPolicy`. `diagnostics` y
    // `configuration` ni siquiera pasan del middleware.

    // arrange
    [$cocina] = departamentosDelCatalogo();
    $token = SupportGrants::tokenFor($scope);

    // act / assert
    Api::as($token)->get('/api/v1/departments')->assertStatus(403);
    Api::as($token)->get('/api/v1/departments/'.$cocina)->assertStatus(403);
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19', 'ADR-020', 'RQ-07');

it('no deja leer el catalogo al quiosco ni al portal del empleado', function (): void {
    // arrange
    [$cocina] = departamentosDelCatalogo();
    $site = WorkforceFixtures::onlySiteId();
    $portal = PortalLogins::open(WorkforceFixtures::employee($site, $cocina));

    // act / assert
    foreach ([ManagementUsers::kioskToken(), $portal] as $token) {
        // Cada token resuelve su propio portador: el guard de Sanctum cachea el
        // usuario dentro de la misma aplicacion de prueba.
        Auth::forgetGuards();

        Api::as($token)->get('/api/v1/departments')->assertStatus(403);
        Api::as($token)->get('/api/v1/departments/'.$cocina)->assertStatus(403);
    }
})->group('RS-04', 'RF-ID-04', 'RF-ID-07', 'RQ-07');

it('pide sesion para leer el catalogo', function (): void {
    // arrange
    [$cocina] = departamentosDelCatalogo();

    // act / assert
    Api::guest()->get('/api/v1/departments')
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');

    Api::guest()->get('/api/v1/departments/'.$cocina)->assertValidResponse(401);
})->group('RQ-07', 'RQ-06');
