<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: autorizacion negativa de `GET /api/v1/license` y
 * `POST /api/v1/license/activate`, rol por rol.
 *
 * Las dos son `[rol: admin]` (Anexo B del doc 01) y el ambito `license:*` es del
 * administrador de instalacion (§7.3). Se comprueban las dos mitades: el
 * middleware que mira el ambito y la policy que mira el rol. Sin la segunda,
 * bastaria un token emitido a mano con el ambito correcto para leer el nombre
 * del cliente, su plan y sus cifras de plantilla, o para sustituir la licencia.
 *
 * **El quiosco tambien se prueba**, y es la mitad que mas importa: la regla dura
 * 19 dice que el quiosco no se entera de la licencia por ningun camino, ni en el
 * de fichaje ni en ningun otro.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function licenseRoutes(): array
{
    return [
        'consulta' => ['GET', '/api/v1/license', []],
        'activacion' => ['POST', '/api/v1/license/activate', ['signed_key' => 'KQL1.a.b']],
    ];
}

it('no deja entrar sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)->assertStatus(401);

    expect(DB::table('license')->count())->toBe(0);
})->with(licenseRoutes())->group('RF-PD-04');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    foreach (licenseRoutes() as [$method, $uri, $body]) {
        Api::as($token)->call($method, $uri, $body)->assertStatus(403);
    }

    expect(DB::table('license')->count())->toBe(0);
})->with([
    // Quien mas usa los informes que la licencia gobierna, y aun asi no entra:
    // lo que se contrato lo decide quien firma el contrato.
    'rrhh' => [UserRole::RRHH],
    // Su trabajo es el registro horario, y la promesa del producto es que el
    // registro NO depende de la licencia (ADR-019).
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-04');

it('rechaza el token de un quiosco', function (): void {
    // Regla dura 19, segunda mitad: el quiosco no se entera de la licencia por
    // ningun camino. Su token lleva tres ambitos y ninguno es `license:*`, asi
    // que se queda en el middleware.
    $escenario = AttendanceFixtures::scenario();

    foreach (licenseRoutes() as [$method, $uri, $body]) {
        Api::as($escenario['token'])->call($method, $uri, $body)->assertStatus(403);
    }
})->group('RF-PD-04', 'RS-04');

it('rechaza la sesion del portal del empleado', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $employee = WorkforceFixtures::employee($siteId);
    $session = PortalLogins::open($employee);

    foreach (licenseRoutes() as [$method, $uri, $body]) {
        Api::as($session)->call($method, $uri, $body)->assertStatus(403);
    }
})->group('RF-PD-04', 'RF-ID-07');

it('deja entrar a admin', function (): void {
    // La otra mitad de la prueba negativa: si nadie entrara, un `return false`
    // en la policy dejaria la suite en verde y el producto sin pantalla de
    // licencia.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/license')->assertOk();
    Api::as($token)
        ->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue()])
        ->assertOk();

    expect(DB::table('license')->count())->toBe(1);
})->group('RF-PD-04');
