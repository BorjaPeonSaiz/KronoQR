<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: autorizacion negativa de las tres rutas de
 * `/api/v1/data-export`, rol por rol y token por token.
 *
 * Son `[rol: admin]` (Anexo B del doc 01) con ambito `settings:*` (§7.3, nota
 * 6). Se comprueban las dos mitades: el middleware que mira el ambito y la
 * policy que mira el rol.
 *
 * ## LOS TRES ALCANCES DE SOPORTE SON EL CASO QUE MAS IMPORTA
 *
 * Regla dura 16 y ADR-020: **el fabricante no accede a los datos del cliente**.
 * Una concesion con alcance `configuration` lleva `settings:*` y actua como
 * `admin` ante las policies —tiene que hacerlo, o no podria cambiar un ajuste—,
 * asi que **pasa el middleware** y lo unico que la para es
 * `DataExportPolicy`. Si esa comprobacion se cayera, un token de soporte podria
 * llevarse una copia completa de la plantilla del hotel: es la escalada mas
 * grave que este producto puede tener, y no hay ninguna otra puerta detras.
 *
 * ## El quiosco y el portal tambien
 *
 * Por lo mismo que en el diagnostico: el fichero describe la instalacion entera,
 * y ni la tablet de la puerta de personal ni el movil de un empleado tienen nada
 * que ver con eso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

/** Ninguna peticion rechazada puede dejar fila ni asiento. */
function nadaSeHaExportado(): void
{
    expect(DB::table('data_exports')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'data_export.%')->count())->toBe(0);
}

it('no deja entrar sin token en ninguna de las tres', function (): void {
    Api::guest()->get('/api/v1/data-export')->assertStatus(401);
    Api::guest()->post('/api/v1/data-export')->assertStatus(401);
    Api::guest()
        ->get('/api/v1/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download')
        ->assertStatus(401);

    nadaSeHaExportado();
})->group('RF-PD-14', 'RS-04');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)->get('/api/v1/data-export')->assertStatus(403);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(403);
    Api::as($token)
        ->get('/api/v1/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download')
        ->assertStatus(403);

    nadaSeHaExportado();
})->with([
    // Gestiona a diario los datos que el fichero contiene, y aun asi no entra:
    // gestionarlos dentro del producto, con su alcance y su auditoria, no es lo
    // mismo que sacarlos en un ZIP.
    'rrhh' => [UserRole::RRHH],
    // Lo que necesita entregar a la Inspeccion es la exportacion legal
    // (RF-IN-05), acotada por periodo y por persona.
    'auditor' => [UserRole::AUDITOR],
    // Su alcance es su departamento; esto es la instalacion entera.
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-14', 'RQ-07');

it('rechaza los TRES alcances de soporte, aunque pasen el ambito', function (SupportScope $scope): void {
    /*
     * LA PRUEBA QUE PROTEGE LA REGLA DURA 16. Un token de soporte con alcance
     * `configuration` lleva `settings:*` y llega hasta la policy; los otros dos
     * se quedan en el middleware. Los tres se prueban igual porque lo que
     * importa no es donde se paran, sino que **ninguno pasa**.
     */
    $token = SupportGrants::tokenFor($scope);

    Api::as($token)->get('/api/v1/data-export')->assertStatus(403);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(403);
    Api::as($token)
        ->get('/api/v1/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download')
        ->assertStatus(403);

    nadaSeHaExportado();
})->with([
    'diagnostics' => [SupportScope::Diagnostics],
    'read_only' => [SupportScope::ReadOnly],
    'configuration' => [SupportScope::Configuration],
])->group('RF-PD-14', 'RF-PD-11', 'RS-04');

it('rechaza un token de soporte al que alguien le hubiera puesto el ambito a mano', function (): void {
    // La otra mitad de la regla dura 18: **la policy cierra aunque el ambito
    // abra**. Es el escenario de un token emitido a mano, de un ambito añadido
    // por error o de un refactor de la lista de alcances.
    $token = SupportGrants::tokenWithAbilities(['settings:*'], SupportScope::Configuration);

    Api::as($token)->get('/api/v1/data-export')->assertStatus(403);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(403);

    nadaSeHaExportado();
})->group('RF-PD-14', 'RF-PD-11', 'RS-04');

it('rechaza el token de un quiosco', function (): void {
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])->get('/api/v1/data-export')->assertStatus(403);
    Api::as($escenario['token'])->post('/api/v1/data-export')->assertStatus(403);
    Api::as($escenario['token'])
        ->get('/api/v1/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download')
        ->assertStatus(403);

    nadaSeHaExportado();
})->group('RF-PD-14', 'RS-04');

it('rechaza la sesion del portal del empleado', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $employee = WorkforceFixtures::employee($siteId);
    $session = PortalLogins::open($employee);

    Api::as($session)->get('/api/v1/data-export')->assertStatus(403);
    Api::as($session)->post('/api/v1/data-export')->assertStatus(403);
    Api::as($session)
        ->get('/api/v1/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download')
        ->assertStatus(403);

    nadaSeHaExportado();
})->group('RF-PD-14', 'RF-ID-07', 'RS-04');

it('deja entrar a admin en las tres', function (): void {
    // La otra mitad de la prueba negativa: si nadie entrara, un `return false`
    // en la policy dejaria la suite en verde y al cliente sin sus datos.
    Queue::fake();

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($usuario);

    Api::as($token)->get('/api/v1/data-export')->assertStatus(200);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(202);

    $export = DataExports::completed(requestedByUserId: $usuario->id);

    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);
})->group('RF-PD-14', 'RL-20');
