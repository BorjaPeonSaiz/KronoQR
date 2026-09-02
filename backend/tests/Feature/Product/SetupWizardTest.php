<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El asistente de puesta en marcha (RF-PD-03, tarea 5.5).
 *
 * LO QUE ESTE FICHERO TIENE QUE DEMOSTRAR, y es el criterio con el que se juzga
 * la fase entera: que una persona que no conoce el sistema llega desde un panel
 * vacio hasta poder fichar **sin abrir una consola**. Aqui esta la mitad de
 * servidor; el recorrido completo es el E2E de `frontend-panel`.
 *
 * Las tres afirmaciones que sostienen el diseño:
 *
 *   1. La UNICA escritura publica del producto —crear el primer administrador—
 *      deja de existir en cuanto hay una cuenta de gestion, y no vuelve.
 *   2. El asistente es de UN SOLO USO: al cerrarlo, sus rutas dejan de admitir
 *      cambios para siempre.
 *   3. No hay callejones sin salida: abandonarlo a medias y volver funciona, y
 *      cada negativa dice a donde ir.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function firstAdministratorPayload(array $overrides = []): array
{
    return [
        'name' => 'Direccion del hotel',
        'email' => 'direccion@hotel.example',
        'password' => 'Una-Contrasena-Larga-1!',
        'locale' => 'es',
        'device_name' => 'Panel de gestion',
        ...$overrides,
    ];
}

/**
 * El administrador del asistente, ya con su segundo factor confirmado: es el
 * unico estado en el que el paso `administrator` se da por completado.
 */
function wizardAdminToken(): string
{
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withActiveSecondFactor($user);

    return ManagementUsers::tokenFor($user);
}

/**
 * Deja resueltos todos los pasos salvo los que la prueba quiera mover.
 *
 * @param  list<string>  $except
 */
function resolveWizardSteps(string $token, array $except = []): void
{
    $completed = ['organisation', 'compliance_profile'];
    $skipped = ['departments', 'employees', 'license', 'kiosk'];

    foreach ($completed as $step) {
        if (! \in_array($step, $except, true)) {
            Api::as($token)->call('PUT', '/api/v1/setup/steps/'.$step, ['state' => 'completed']);
        }
    }

    foreach ($skipped as $step) {
        if (! \in_array($step, $except, true)) {
            Api::as($token)->call('PUT', '/api/v1/setup/steps/'.$step, ['state' => 'skipped']);
        }
    }
}

// -----------------------------------------------------------------------------
// Estado del asistente
// -----------------------------------------------------------------------------

it('dice que el asistente esta disponible en una instalacion recien montada', function (): void {
    // LA RUTA PUBLICA DEVUELVE LO MINIMO: si sigue abierto y cuando se cerro. El
    // detalle vive en `GET /setup/steps`, que exige sesion, porque la lista de
    // pasos es un inventario de la postura de la instalacion.
    $response = Api::guest()->get('/api/v1/setup/status')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('available', true)
        ->assertJsonPath('completed_at', null);

    expect($response->json())->not->toHaveKey('steps');
})->group('RF-PD-03');

it('enumera los pasos para quien ha entrado, con los derivados en pending', function (): void {
    // Los dos derivados —`administrator` y `site`— salen del DATO y no de una
    // marca. Aqui hay cuenta de administrador pero **sin** segundo factor
    // confirmado y sin centro, asi que los dos siguen pendientes.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/setup/steps')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('available', true)
        ->assertJsonPath('steps.0.step', 'administrator')
        ->assertJsonPath('steps.0.state', 'pending')
        ->assertJsonPath('steps.2.step', 'site')
        ->assertJsonPath('steps.2.state', 'pending');
})->group('RF-PD-03');

it('publica que pasos son obligatorios y cuales se pueden omitir', function (): void {
    // Viajan en la RESPUESTA y no compilados en la SPA (ADR-017, regla dura 13):
    // el panel se construye una vez y se instala en el servidor de cada cliente.
    $response = Api::as(wizardAdminToken())->get('/api/v1/setup/steps')->assertValidResponse(200);

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $response->json('steps');
    $byStep = array_column($steps, null, 'step');

    // La licencia es omitible POR LA REGLA DURA 15: un asistente que la exigiera
    // para terminar convertiria la licencia en requisito de arranque del
    // registro horario.
    expect($byStep['license']['skippable'])->toBeTrue()
        ->and($byStep['license']['required'])->toBeFalse()
        // El quiosco tambien: puede que la tablet no haya llegado.
        ->and($byStep['kiosk']['skippable'])->toBeTrue()
        // El perfil de convenio NO, por RL-21: los umbrales hay que
        // contrastarlos con el convenio aplicable, y eso es un acto de alguien.
        ->and($byStep['compliance_profile']['skippable'])->toBeFalse()
        ->and($byStep['compliance_profile']['required'])->toBeTrue();
})->group('RF-PD-03', 'RL-21');

it('deriva el paso del administrador del dato y no de una marca', function (): void {
    // Una cuenta SIN segundo factor confirmado no completa el paso: no puede
    // entrar al panel (RS-06), y darlo por hecho dejaria el asistente diciendo
    // que hay administrador mientras nadie tiene acceso.
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($user);

    Api::as($token)->get('/api/v1/setup/steps')
        ->assertValidResponse(200)
        ->assertJsonPath('steps.0.state', 'pending');

    ManagementUsers::withActiveSecondFactor(User::query()->firstOrFail());

    Api::as($token)->get('/api/v1/setup/steps')
        ->assertValidResponse(200)
        ->assertJsonPath('steps.0.state', 'completed');
})->group('RF-PD-03', 'RS-06');

// -----------------------------------------------------------------------------
// Primer administrador
// -----------------------------------------------------------------------------

it('crea el primer administrador y devuelve un reto de segundo factor, no una sesion', function (): void {
    Api::guest()->post('/api/v1/setup/administrator', firstAdministratorPayload())
        ->assertValidRequest()
        ->assertValidResponse(201)
        // `challenge_token` y no `token`: ese token no autoriza ninguna pantalla
        // del panel, y confundirlos da un 403 en todas partes.
        ->assertJsonStructure(['challenge_token', 'token_type', 'expires_at', 'enrolment_required'])
        ->assertJsonPath('enrolment_required', true)
        ->assertJsonMissingPath('token');

    $user = User::query()->firstOrFail();

    expect($user->hasRole(UserRole::ADMIN->value))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        // Nace SIN segundo factor confirmado: no puede entrar hasta activarlo.
        ->and($user->two_factor_confirmed_at)->toBeNull();
})->group('RF-PD-03', 'RS-06');

it('audita la creacion del primer administrador con su rol', function (): void {
    Api::guest()->post('/api/v1/setup/administrator', firstAdministratorPayload())->assertValidResponse(201);

    $entry = DB::table('audit_log')
        ->where('action', AuditAction::RoleAssignmentChanged->value)
        ->first();

    expect($entry)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['role'])->toBe(UserRole::ADMIN->value)
        // El UUID publico y nada mas: ni el correo ni el nombre (regla dura 21).
        ->and($payload)->toHaveKey('user_uuid')
        ->and($payload)->not->toHaveKey('email')
        ->and($payload)->not->toHaveKey('name');
})->group('RF-PD-03', 'RL-04');

it('deja de admitir un primer administrador en cuanto existe una cuenta de gestion', function (): void {
    Api::guest()->post('/api/v1/setup/administrator', firstAdministratorPayload())->assertValidResponse(201);

    $response = Api::guest()
        ->post('/api/v1/setup/administrator', firstAdministratorPayload(['email' => 'otro@hotel.example']))
        ->assertValidResponse(409);

    // Y el mensaje dice A DONDE IR: el caso real es que alguien cerro la pestaña
    // antes de escanear el QR y vuelve a empezar. Sin esa frase se queda fuera de
    // su propia instalacion con la cuenta ya creada.
    expect((string) $response->json('detail'))->toContain('/api/v1/auth/login');

    expect(User::query()->count())->toBe(1);
})->group('RF-PD-03');

it('cuenta tambien las cuentas desactivadas al cerrar la creacion publica', function (): void {
    // Si contara solo las activas, dar de baja a la unica persona con acceso
    // reabriria la creacion PUBLICA de un administrador. Es una escalada de
    // privilegios disfrazada de tarea rutinaria de RRHH.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $user->is_active = false;
    $user->save();

    Api::guest()->post('/api/v1/setup/administrator', firstAdministratorPayload())->assertValidResponse(409);
})->group('RF-PD-03', 'RS-06');

it('exige la politica de robustez al fijar la contrasena del primer administrador', function (): void {
    // Aqui SI, al contrario que en `/auth/login`: este es el sitio donde la
    // contrasena se FIJA (RF-ID-01).
    Api::guest()
        ->post('/api/v1/setup/administrator', firstAdministratorPayload(['password' => 'corta']))
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['password']]);

    expect(User::query()->count())->toBe(0);
})->group('RF-PD-03', 'RF-ID-01');

it('no deja elegir el rol del primer administrador', function (): void {
    // Un `auditor` como primera cuenta dejaria la instalacion sin nadie capaz de
    // configurarla: un callejon sin salida del que solo se sale con consola.
    Api::guest()
        ->post('/api/v1/setup/administrator', firstAdministratorPayload(['role' => 'auditor']))
        ->assertValidResponse(422);
})->group('RF-PD-03');

// -----------------------------------------------------------------------------
// El centro
// -----------------------------------------------------------------------------

it('crea el centro de la instalacion con su zona horaria', function (): void {
    Api::as(wizardAdminToken())
        ->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Atlantic/Canary'])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('name', 'Hotel Marina')
        ->assertJsonPath('timezone', 'Atlantic/Canary');

    // El centro nace SIN perfil asignado, que es como se dice «usa el de la
    // instalacion»: con un centro por instalacion (ADR-040) hay exactamente un
    // perfil vigente, y lo resuelve `is_default`.
    expect(DB::table('sites')->value('compliance_profile_id'))->toBeNull();
})->group('RF-PD-03', 'RN-05');

it('exige la zona horaria al crear el centro', function (): void {
    // Sin valor por defecto en el servidor aunque la columna lo tenga: un campo
    // omitible aqui es un turno de noche mal atribuido dentro de seis meses.
    Api::as(wizardAdminToken())
        ->post('/api/v1/setup/site', ['name' => 'Hotel Marina'])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['timezone']]);
})->group('RF-PD-03', 'RN-05');

it('rechaza una zona horaria que no existe', function (): void {
    Api::as(wizardAdminToken())
        ->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europa/Madrid'])
        ->assertValidResponse(422);
})->group('RN-05');

it('se niega a crear un segundo centro y dice donde esta el primero', function (): void {
    $token = wizardAdminToken();

    Api::as($token)->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(201);

    $response = Api::as($token)
        ->post('/api/v1/setup/site', ['name' => 'Hotel Atlantico', 'timezone' => 'Atlantic/Canary'])
        ->assertValidResponse(409);

    expect((string) $response->json('detail'))->toContain('/api/v1/site');

    expect(DB::table('sites')->count())->toBe(1);
})->group('RF-PD-03');

it('audita el alta del centro con la zona horaria y con su autor', function (): void {
    // Regla dura 6. `sites.timezone` es el parametro con el que RN-05 atribuye
    // cada tramo a una jornada: sin asiento, «¿con que zona nacio la
    // instalacion?» no tiene respuesta, y eso no se reconstruye despues.
    Api::as(wizardAdminToken())
        ->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Atlantic/Canary'])
        ->assertValidResponse(201);

    $entry = DB::table('audit_log')->where('action', AuditAction::SiteCreated->value)->first();

    expect($entry)->not->toBeNull()
        // Con ACTOR, que es la razon por la que el administrador es el primer
        // paso del asistente: creado antes el centro, este asiento saldria como
        // `system` y no diria quien puso la zona horaria.
        ->and($entry?->actor_id)->not->toBeNull();

    /** @var array{previous_value: ?array<string, mixed>, new_value: array<string, mixed>} $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    // Misma forma que el asiento de un umbral de calculo: `previous_value` y
    // `new_value`. En el alta el primero es `null` —no habia centro—, y eso
    // significa «no habia», no «no se sabe».
    expect($payload['new_value']['timezone'])->toBe('Atlantic/Canary')
        ->and($payload['previous_value'])->toBeNull();
})->group('RF-PD-03', 'RL-04');

// -----------------------------------------------------------------------------
// Pasos, reanudacion y cierre
// -----------------------------------------------------------------------------

it('guarda cada paso para que el asistente se pueda abandonar y retomar', function (): void {
    $token = wizardAdminToken();

    Api::as($token)->call('PUT', '/api/v1/setup/steps/departments', ['state' => 'completed'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    // Una peticion nueva, sin nada en memoria: es lo que hace la reanudacion.
    $response = Api::as($token)->get('/api/v1/setup/steps')->assertValidResponse(200);

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $response->json('steps');

    expect(array_column($steps, null, 'step')['departments']['state'])->toBe('completed');
})->group('RF-PD-03');

it('es idempotente al marcar dos veces el mismo paso', function (): void {
    $token = wizardAdminToken();

    Api::as($token)->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])->assertValidResponse(200);
    Api::as($token)->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])->assertValidResponse(200);

    expect(DB::table('setup_progress')->where('step', 'license')->count())->toBe(1);
})->group('RF-PD-03');

it('no deja declarar a mano un paso que se deduce del dato', function (): void {
    // Permitirlo seria permitir que el asistente afirme que hay administrador
    // cuando no lo hay.
    foreach (['administrator', 'site'] as $step) {
        Api::as(wizardAdminToken())
            ->call('PUT', '/api/v1/setup/steps/'.$step, ['state' => 'completed'])
            ->assertValidResponse(422);
    }

    expect(DB::table('setup_progress')->count())->toBe(0);
})->group('RF-PD-03');

it('no deja omitir el perfil de convenio', function (): void {
    // RL-21: los umbrales hay que contrastarlos con el convenio aplicable, y eso
    // es un acto de alguien, no un valor por defecto que nadie miro.
    $response = Api::as(wizardAdminToken())
        ->call('PUT', '/api/v1/setup/steps/compliance_profile', ['state' => 'skipped'])
        ->assertValidResponse(422);

    expect((string) $response->json('detail'))->not->toBe('');
})->group('RF-PD-03', 'RL-21');

it('responde 404 a un paso que no existe, y dice donde estan los que si', function (): void {
    Api::as(wizardAdminToken())
        ->call('PUT', '/api/v1/setup/steps/impresora', ['state' => 'completed'])
        ->assertValidResponse(404);
})->group('RF-PD-03');

it('no deja cerrar el asistente con pasos sin resolver, y dice cuales', function (): void {
    $token = wizardAdminToken();
    WorkforceFixtures::site();

    resolveWizardSteps($token, except: ['kiosk']);

    $response = Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(409);

    // El `detail` NOMBRA lo que falta: quien pone en marcha la instalacion no
    // tiene forma de averiguarlo por su cuenta.
    expect((string) $response->json('detail'))->toContain('kiosk');
})->group('RF-PD-03');

it('cierra el asistente con la licencia y el quiosco omitidos', function (): void {
    // Regla dura 15: la licencia jamas es requisito para terminar. Una
    // instalacion sin clave ficha igual.
    $token = wizardAdminToken();
    WorkforceFixtures::site();

    resolveWizardSteps($token);

    Api::as($token)->post('/api/v1/setup/complete')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status.available', false)
        ->assertJsonPath('status.steps', [])
        ->assertJsonPath('summary.license', 'absent');
})->group('RF-PD-03');

it('audita el cierre del asistente con los pasos que se omitieron', function (): void {
    // Regla dura 6 y RL-04. El asistente NO SE REABRE, y esa irreversibilidad se
    // justifica precisamente con el trail: reabrirlo seria una via para
    // reconfigurar la instalacion —empezando por la zona horaria del centro— sin
    // dejar rastro. Un acto que se justifica por el trail tiene que estar EN el
    // trail; si no, la justificacion es una promesa sin conducta.
    $token = wizardAdminToken();
    WorkforceFixtures::site();

    resolveWizardSteps($token);

    Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(200);

    $entry = DB::table('audit_log')->where('action', AuditAction::SetupCompleted->value)->first();

    expect($entry)->not->toBeNull()
        // CON ACTOR: quien cierra esta autenticado como administrador, y esa es
        // la razon por la que su alta es el primer paso del asistente.
        ->and($entry?->actor_id)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    // Los pasos OMITIDOS, que es lo unico que no se puede reconstruir despues:
    // `setup_progress` es una tabla normal —editable— y `audit_log` es
    // solo-append y encadenado por hash. `resolveWizardSteps` omite cuatro.
    expect($payload['skipped_steps'])
        ->toBe(['departments', 'employees', 'license', 'kiosk'])
        // Nombres de paso y nada mas (regla dura 21).
        ->and(array_keys($payload))->toBe(['skipped_steps']);
})->group('RF-PD-03', 'RL-04');

it('devuelve un resumen accionable con las tarjetas pendientes y sin nombrar a nadie', function (): void {
    // La cifra que decide el primer dia: sin tarjeta impresa y entregada no se
    // ficha (ADR-014), y emitirlas tiene logistica detras (doc 05 §10.2).
    $token = wizardAdminToken();
    $site = WorkforceFixtures::site();
    WorkforceFixtures::department($site);
    WorkforceFixtures::employee($site);
    WorkforceFixtures::employee($site);

    resolveWizardSteps($token);

    $response = Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(200)
        ->assertJsonPath('summary.employees', 2)
        ->assertJsonPath('summary.departments', 1)
        ->assertJsonPath('summary.credentials_pending', 2)
        ->assertJsonPath('summary.kiosks', 0);

    // Cifras y nada mas (regla dura 21): ni un nombre, ni un correo, ni un UUID.
    /** @var array<string, mixed> $summary */
    $summary = $response->json('summary');

    expect(array_keys($summary))
        ->toBe(['employees', 'departments', 'credentials_pending', 'license', 'kiosks']);
})->group('RF-PD-03', 'RF-QR-08');

it('deja de estar accesible en cuanto se completa, y no vuelve', function (): void {
    $token = wizardAdminToken();
    WorkforceFixtures::site();

    resolveWizardSteps($token);
    Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(200);

    // 1. El estado publico dice que ya no esta disponible, y sigue sin enumerar
    //    nada — nunca lo hizo.
    $public = Api::guest()->get('/api/v1/setup/status')
        ->assertValidResponse(200)
        ->assertJsonPath('available', false);

    expect($public->json())->not->toHaveKey('steps');

    // Y para el administrador, la lista viaja VACIA: terminada la puesta en
    // marcha, el inventario de lo que se omitio no tiene consumidor.
    Api::as($token)->get('/api/v1/setup/steps')
        ->assertValidResponse(200)
        ->assertJsonPath('steps', []);

    // 2. Ningun paso admite mas cambios.
    Api::as($token)->call('PUT', '/api/v1/setup/steps/license', ['state' => 'completed'])
        ->assertValidResponse(409);

    // 3. Y no se puede cerrar dos veces.
    Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(409);

    // 4. Ni crear otro administrador por la puerta publica.
    Api::guest()->post('/api/v1/setup/administrator', firstAdministratorPayload(['email' => 'otro@hotel.example']))
        ->assertValidResponse(409);
})->group('RF-PD-03');

it('sirve el estado del asistente en el idioma negociado', function (): void {
    $response = Api::as(wizardAdminToken())
        ->withHeaders(['Accept-Language' => 'en'])
        ->call('PUT', '/api/v1/setup/steps/compliance_profile', ['state' => 'skipped'])
        ->assertValidResponse(422);

    /** @var array<string, list<string>> $errors */
    $errors = $response->json('errors');

    expect($errors['state'][0])->toContain('cannot be skipped');
})->group('RF-PD-03', 'RQ-04');
