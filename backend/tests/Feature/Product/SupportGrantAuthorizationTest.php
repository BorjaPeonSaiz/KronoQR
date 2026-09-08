<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: autorizacion negativa de las tres rutas de
 * `/api/v1/support/grants`, rol por rol y token por token.
 *
 * Las tres son `[rol: admin]` (Anexo B del doc 01) y el ambito `support:*` es
 * del administrador de instalacion (§7.3). Se comprueban las dos mitades: el
 * middleware que mira el ambito y la policy que mira el rol.
 *
 * Y UNA TERCERA COMPROBACION QUE NINGUNA OTRA POLICY DEL PRODUCTO NECESITA: que
 * quien pregunta no sea el propio fabricante. Un token de soporte con alcance
 * `configuration` actua como `admin` ante las policies —tiene que hacerlo, o no
 * podria cambiar un ajuste—, asi que sin `isSupportActor()` seria indistinguible
 * del administrador del hotel justo en el endpoint que decide cuanto acceso
 * tiene. Ahi la escalada seria completa: concederse a si mismo 72 horas de
 * `read_only`, o revocar la concesion que el cliente acaba de cortar.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function supportGrantRoutes(): array
{
    return [
        'lista' => ['GET', '/api/v1/support/grants', []],
        'concesion' => ['POST', '/api/v1/support/grants', ['reason' => 'Incidencia #123']],
        'revocacion' => ['DELETE', '/api/v1/support/grants/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', []],
    ];
}

it('no deja entrar sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)->assertStatus(401);

    expect(DB::table('support_grants')->count())->toBe(0);
})->with(supportGrantRoutes())->group('RF-PD-11');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    foreach (supportGrantRoutes() as [$method, $uri, $body]) {
        Api::as($token)->call($method, $uri, $body)->assertStatus(403);
    }

    expect(DB::table('support_grants')->count())->toBe(0);
})->with([
    // Gestiona los datos que un acceso de soporte podria llegar a leer, y aun asi
    // no entra: autorizar la entrada del fabricante es una decision de quien
    // responde de la instalacion, y es la firma del encargo del art. 28 (RL-18).
    'rrhh' => [UserRole::RRHH],
    // Quien vigila no autoriza. Lo que necesite sobre accesos de soporte lo tiene
    // en `audit_log` con `audit:read`.
    'auditor' => [UserRole::AUDITOR],
    // Su alcance es su departamento, y esto no es de ningun departamento.
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-11');

it('rechaza el token de un quiosco', function (): void {
    $escenario = AttendanceFixtures::scenario();

    foreach (supportGrantRoutes() as [$method, $uri, $body]) {
        Api::as($escenario['token'])->call($method, $uri, $body)->assertStatus(403);
    }
})->group('RF-PD-11', 'RS-04');

it('rechaza la sesion del portal del empleado', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $employee = WorkforceFixtures::employee($siteId);
    $session = PortalLogins::open($employee);

    foreach (supportGrantRoutes() as [$method, $uri, $body]) {
        Api::as($session)->call($method, $uri, $body)->assertStatus(403);
    }
})->group('RF-PD-11', 'RF-ID-07');

it('rechaza al PROPIO fabricante, con cualquiera de los tres alcances', function (SupportScope $scope): void {
    // La prueba central de esta tarea. Quien recibe el acceso no decide si se le
    // amplia ni si se le retira, y eso vale tambien para el alcance
    // `configuration`, que ante las demas policies es un `admin`.
    $token = SupportGrants::tokenFor($scope);
    $antes = DB::table('support_grants')->count();

    foreach (supportGrantRoutes() as [$method, $uri, $body]) {
        Api::as($token)->call($method, $uri, $body)->assertStatus(403);
    }

    // Ni siquiera la lista: saber que otras concesiones hay abiertas no le hace
    // falta a soporte para arreglar nada, y le diria cuando le van a cortar.
    expect(DB::table('support_grants')->count())->toBe($antes);
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');

it('deja entrar a admin', function (): void {
    // La otra mitad de la prueba negativa: si nadie entrara, un `return false` en
    // la policy dejaria la suite en verde y el producto sin forma de conceder
    // soporte.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/support/grants')->assertOk();
    Api::as($token)->post('/api/v1/support/grants', ['reason' => 'Incidencia #123'])->assertStatus(201);

    expect(DB::table('support_grants')->count())->toBe(1);
})->group('RF-PD-11');
