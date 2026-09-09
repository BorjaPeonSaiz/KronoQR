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
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: autorizacion negativa de las dos rutas del historico,
 * rol por rol (RF-PD-15, Anexo B: `[rol: admin]`).
 *
 * ## La asimetria del acceso de soporte es lo que mas importa de este fichero
 *
 * Un token de soporte con alcance `diagnostics` **lee** el historico —se le
 * concede «para el paquete anonimizado y los errores» (decision 8)— y **no lo
 * resuelve**: dar un fallo por atendido en la instalacion de un cliente es una
 * decision del cliente (ADR-020, regla dura 16). Sin la prueba, esa distincion
 * se pierde en la primera refactorizacion.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/** Un grupo abierto sobre el que autorizar. */
function grupoParaAutorizar(): int
{
    $ahora = now()->toDateTimeString('microsecond');

    return (int) DB::table('error_events')->insertGetId([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'api',
        'module' => 'product',
        'message' => 'algo fallo',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php',
        'line' => 10,
        'context' => '{}',
        'app_version' => '2.2.0',
        'occurrences' => 1,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);
}

it('no deja entrar sin token', function (): void {
    Api::guest()->get('/api/v1/diagnostics/errors')->assertStatus(401);
    Api::guest()->post('/api/v1/diagnostics/errors/'.grupoParaAutorizar().'/resolve')->assertStatus(401);
})->group('RF-PD-15');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));
    $id = grupoParaAutorizar();

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertStatus(403);
    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertStatus(403);

    expect(DB::table('error_events')->where('id', $id)->value('resolved_at'))->toBeNull();
})->with([
    // Suele ser quien detecta el sintoma, y aun asi no entra: lo que hay detras
    // es el estado tecnico del servidor, y lo que se decide con el es de quien
    // lo administra.
    'rrhh' => [UserRole::RRHH],
    // Su ambito es el registro horario, y estos errores no forman parte de el.
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-15');

it('rechaza el token de un quiosco', function (): void {
    // Su token lleva tres ambitos y ninguno es `diagnostics:*`, asi que se queda
    // en el middleware; la policy lo rechazaria igualmente.
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])->get('/api/v1/diagnostics/errors')->assertStatus(403);
    Api::as($escenario['token'])
        ->post('/api/v1/diagnostics/errors/'.grupoParaAutorizar().'/resolve')
        ->assertStatus(403);
})->group('RF-PD-15', 'RS-04');

it('rechaza la sesion del portal del empleado', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $session = PortalLogins::open(WorkforceFixtures::employee($siteId));

    Api::as($session)->get('/api/v1/diagnostics/errors')->assertStatus(403);
    Api::as($session)
        ->post('/api/v1/diagnostics/errors/'.grupoParaAutorizar().'/resolve')
        ->assertStatus(403);
})->group('RF-PD-15', 'RF-ID-07');

it('deja entrar a admin en las dos rutas', function (): void {
    // La otra mitad de la prueba negativa: si nadie entrara, un `return false`
    // en la policy dejaria la suite en verde y al cliente sin pantalla.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertOk();
    Api::as($token)->post('/api/v1/diagnostics/errors/'.grupoParaAutorizar().'/resolve')->assertOk();
})->group('RF-PD-15');

it('un acceso de soporte con alcance diagnostics LEE el historico', function (): void {
    // Es para lo que se le concede: «el paquete anonimizado y el historico de
    // errores» (SupportScope::Diagnostics). Aqui no hay datos personales que
    // proteger, asi que no hay ninguna razon para cerrarle la lista.
    Api::as(SupportGrants::tokenFor(SupportScope::Diagnostics))
        ->get('/api/v1/diagnostics/errors')
        ->assertOk();
})->group('RF-PD-15', 'RF-PD-11');

it('un acceso de soporte no ve QUIEN dio un fallo por resuelto', function (): void {
    /*
     * Decision 14. `resolved_by.name` es **la unica columna del historico con un
     * nombre de persona dentro**: la cuenta de gestion del hotel que lo atendio.
     * El paquete de diagnostico ya la excluia por el mismo motivo —no ayuda a
     * diagnosticar nada y es informacion de la organizacion del cliente,
     * ADR-020 y regla dura 16— y la pantalla se la seguia enseñando.
     *
     * Sale `null` y no un nombre vacio: el esquema `IncidentUser` del contrato
     * exige `name` con `minLength: 1`. El soporte sigue viendo QUE se resolvio y
     * CUANDO, que es lo que necesita.
     */
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $id = grupoParaAutorizar();

    Api::as(ManagementUsers::tokenFor($usuario))
        ->post('/api/v1/diagnostics/errors/'.$id.'/resolve')
        ->assertOk();

    $delCliente = Api::as(ManagementUsers::tokenFor($usuario))
        ->get('/api/v1/diagnostics/errors?status=resolved')
        ->assertOk();

    $deSoporte = Api::as(SupportGrants::tokenFor(SupportScope::Diagnostics))
        ->get('/api/v1/diagnostics/errors?status=resolved')
        ->assertOk();

    expect($delCliente->json('data.0.resolved_by.name'))->toBe($usuario->name)
        // Soporte: sin autor, pero con el instante.
        ->and($deSoporte->json('data.0.resolved_by'))->toBeNull()
        ->and($deSoporte->json('data.0.resolved_at'))->toBeString()
        ->and((string) $deSoporte->getContent())->not->toContain($usuario->name);
})->group('RF-PD-15', 'RF-PD-11', 'RL-19');

it('los tres alcances de soporte leen y NINGUNO resuelve', function (SupportScope $scope): void {
    $token = SupportGrants::tokenFor($scope);
    $id = grupoParaAutorizar();

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertOk();
    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertStatus(403);

    // Y no basta con el `403`: lo que no puede pasar es que la fila se toque.
    expect(DB::table('error_events')->where('id', $id)->value('resolved_at'))->toBeNull();
})->with([
    'diagnostics' => [SupportScope::Diagnostics],
    'read_only' => [SupportScope::ReadOnly],
    'configuration' => [SupportScope::Configuration],
])->group('RF-PD-15', 'RF-PD-11');
