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
 * Regla dura 18 y RQ-07: autorizacion negativa de
 * `POST /api/v1/diagnostics/bundle`, rol por rol.
 *
 * Es `[rol: admin]` (Anexo B del doc 01) y el ambito `diagnostics:*` es del
 * administrador de instalacion (§7.3). Se comprueban las dos mitades: el
 * middleware que mira el ambito y la policy que mira el rol. Sin la segunda,
 * bastaria un token emitido a mano con el ambito correcto para sacar de la
 * instalacion el inventario completo de su configuracion y de su flota.
 *
 * **El quiosco y el portal tambien se prueban**, y por la misma razon que en la
 * licencia: el paquete describe la instalacion entera, y ni la tablet de la
 * puerta de personal ni el movil de un empleado tienen nada que ver con eso.
 *
 * **Lo que NO esta aqui** es el token de soporte pidiendo datos personales: esa
 * prueba vive con el resto de los accesos de soporte (RF-PD-11), porque necesita
 * emitir uno.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

it('no deja entrar sin token', function (): void {
    Api::guest()->post('/api/v1/diagnostics/bundle')->assertStatus(401);

    expect(DB::table('audit_log')->where('action', 'diagnostics.bundle_generated')->count())->toBe(0);
})->group('RF-PD-09');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(403);
    Api::as($token)->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true])->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'diagnostics.bundle_generated')->count())->toBe(0);
})->with([
    // Suele ser quien detecta el problema, y aun asi no entra: el paquete SACA
    // informacion del servidor hacia fuera, y esa es una decision del
    // administrador de la instalacion.
    'rrhh' => [UserRole::RRHH],
    // Su ambito es el registro horario, y este fichero no forma parte de el.
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-09');

it('rechaza el token de un quiosco', function (): void {
    // Su token lleva tres ambitos y ninguno es `diagnostics:*`, asi que se queda
    // en el middleware; la policy lo rechazaria igualmente.
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])->post('/api/v1/diagnostics/bundle')->assertStatus(403);
})->group('RF-PD-09', 'RS-04');

it('rechaza la sesion del portal del empleado', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $employee = WorkforceFixtures::employee($siteId);
    $session = PortalLogins::open($employee);

    Api::as($session)->post('/api/v1/diagnostics/bundle')->assertStatus(403);
})->group('RF-PD-09', 'RF-ID-07');

it('deja entrar a admin, tambien para los datos personales', function (): void {
    // La otra mitad de la prueba negativa: si nadie entrara, un `return false`
    // en la policy dejaria la suite en verde y el producto sin paquete.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(200);
    Api::as($token)
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true])
        ->assertStatus(200);
})->group('RF-PD-09', 'RL-19');
