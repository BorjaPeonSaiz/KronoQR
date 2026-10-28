<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Los tres escenarios de cuentas de gestion del doc 01 §11 que la verificacion
 * final de la 2.2.0 dio por afirmados solo en parte (V3-QA-3):
 *
 * - «Alta de una cuenta de gestión sin correo de invitación»: con el rol
 *   `responsable_departamento` del escenario, sin ningun correo, con el valor
 *   de la caducidad y sin departamentos.
 * - «Primer acceso con contraseña temporal»: activando de verdad el segundo
 *   factor, sin fabricar a mano el token `password:change`.
 * - «Baja de la cuenta de quien deja el hotel»: la cuenta recien dada de baja
 *   sigue en la lista, y lo que firmo sigue a su nombre.
 *
 * Lo que ya afirma `ManagementAccountsApiTest` (asientos sin nombre, `401` de
 * la sesion, `404` repetido) no se repite aqui.
 */

uses(RefreshDatabase::class);

const MANAGEMENT_ACCOUNT_SCENARIOS_AHORA = '2026-10-07 08:00:00';

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    FrozenTime::at(MANAGEMENT_ACCOUNT_SCENARIOS_AHORA);
    config()->set('identity.two_factor.required_roles', []);
    config()->set('identity.temporary_password.ttl_hours', 72);
    RateLimiter::clear('auth-ip:127.0.0.1');
});

/**
 * @param  TestResponse<Response>  $response
 */
function cuentasEscenarioTexto(TestResponse $response, string $path): string
{
    $value = $response->json($path);

    return \is_scalar($value) ? (string) $value : '';
}

/**
 * La «Jefatura de Cocina» dada de alta por una admin, y su contrasena temporal.
 *
 * @return array{response: TestResponse<Response>, temporal: string, email: string}
 */
function cuentasEscenarioAltaDelResponsable(): array
{
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $response = Api::as(ManagementUsers::tokenFor($admin))->post('/api/v1/management-accounts', [
        'name' => 'Jefatura de Cocina',
        'email' => 'cocina@hotel.example',
        'role' => 'responsable_departamento',
        'actor_current_password' => ManagementUsers::PASSWORD,
    ])->assertStatus(201);

    Auth::forgetGuards();

    return ['response' => $response, 'temporal' => cuentasEscenarioTexto($response, 'temporary_password.password'), 'email' => 'cocina@hotel.example'];
}

it('da de alta a un responsable de departamento con la temporal y su caducidad a 72 horas', function (): void {
    $alta = cuentasEscenarioAltaDelResponsable();

    $alta['response']
        ->assertJsonPath('account.roles', ['responsable_departamento'])
        ->assertJsonPath('account.password_status', 'temporary')
        ->assertJsonPath('temporary_password.issued_at', '2026-10-07T08:00:00Z')
        ->assertJsonPath('temporary_password.expires_at', '2026-10-10T08:00:00Z');

    expect(strlen($alta['temporal']))->toBeGreaterThanOrEqual(20);
})->group('RF-ID-10', 'RF-ID-02');

it('no envia ningun correo ni ninguna notificacion al dar de alta una cuenta', function (): void {
    Mail::fake();
    Notification::fake();

    cuentasEscenarioAltaDelResponsable();

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
})->group('RF-ID-10', 'RF-ID-02');

it('deja al responsable recien creado sin departamentos y sin alcanzar a nadie', function (): void {
    $site = WorkforceFixtures::site('Hotel de cuentas');
    WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Cocina'));
    $alta = cuentasEscenarioAltaDelResponsable();

    $alta['response']
        ->assertJsonPath('account.scope.department_ids', []);

    $sesion = cuentasEscenarioTexto(Api::guest()->post('/api/v1/auth/login', [
        'email' => $alta['email'], 'password' => $alta['temporal'], 'device_name' => 'Panel',
    ])->assertStatus(200), 'token');
    Api::as($sesion)->post('/api/v1/auth/password', [
        'current_password' => $alta['temporal'],
        'new_password' => 'Jefatura-Cocina-Propia-2026!',
    ])->assertStatus(204);

    Api::as($sesion)->get('/api/v1/employees')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 0);
})->group('RF-ID-10', 'RF-ID-03');

it('tras activar el segundo factor con la temporal, la sesion solo sirve para cambiarla', function (): void {
    config()->set('identity.two_factor.required_roles', ['admin', 'rrhh', 'auditor', 'responsable_departamento']);
    $alta = cuentasEscenarioAltaDelResponsable();

    $reto = cuentasEscenarioTexto(Api::guest()->post('/api/v1/auth/login', [
        'email' => $alta['email'], 'password' => $alta['temporal'], 'device_name' => 'Panel',
    ])->assertStatus(202)->assertJsonPath('enrolment_required', true), 'challenge_token');
    Auth::forgetGuards();
    $secreto = cuentasEscenarioTexto(Api::as($reto)->post('/api/v1/auth/2fa/enrol')->assertStatus(200), 'secret');
    Auth::forgetGuards();
    $sesion = cuentasEscenarioTexto(Api::as($reto)->post('/api/v1/auth/2fa/confirm', [
        'code' => ManagementUsers::totpCodeFor($secreto),
    ])->assertStatus(200), 'token');
    Auth::forgetGuards();

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(200)->assertJsonPath('password_change_required', true);
    Api::as($sesion)->get('/api/v1/employees')
        ->assertStatus(403)
        ->assertJsonPath('type', ProblemDetails::TYPE_PASSWORD_CHANGE_REQUIRED);
})->group('RF-ID-10', 'RF-ID-01', 'RS-06');

it('tras activar el segundo factor, fijar la suya con la temporal como actual abre la sesion', function (): void {
    config()->set('identity.two_factor.required_roles', ['admin', 'rrhh', 'auditor', 'responsable_departamento']);
    $alta = cuentasEscenarioAltaDelResponsable();

    $reto = cuentasEscenarioTexto(Api::guest()->post('/api/v1/auth/login', [
        'email' => $alta['email'], 'password' => $alta['temporal'], 'device_name' => 'Panel',
    ])->assertStatus(202), 'challenge_token');
    Auth::forgetGuards();
    $secreto = cuentasEscenarioTexto(Api::as($reto)->post('/api/v1/auth/2fa/enrol')->assertStatus(200), 'secret');
    Auth::forgetGuards();
    $sesion = cuentasEscenarioTexto(Api::as($reto)->post('/api/v1/auth/2fa/confirm', [
        'code' => ManagementUsers::totpCodeFor($secreto),
    ])->assertStatus(200), 'token');
    Auth::forgetGuards();

    Api::as($sesion)->post('/api/v1/auth/password', [
        'current_password' => $alta['temporal'],
        'new_password' => 'Jefatura-Cocina-Propia-2026!',
    ])->assertStatus(204);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(200)->assertJsonPath('password_change_required', false);
})->group('RF-ID-10', 'RF-ID-01', 'RS-06');

/**
 * El jefe de recepcion, de RRHH, que ya ha firmado una correccion: el alta a
 * mano de un tramo de Lucia del 6 de octubre.
 *
 * @return array{admin: string, jefe: User, lucia: string}
 */
function cuentasEscenarioJefeQueFirmo(): array
{
    $site = WorkforceFixtures::site('Hotel de la temporada');
    $lucia = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Recepcion'));
    $jefe = ManagementUsers::withRole(UserRole::RRHH, 'recepcion@hotel.example');
    $jefe->name = 'Jefe de Recepcion';
    $jefe->save();

    Api::as(ManagementUsers::tokenFor($jefe))->post('/api/v1/shift-entries', [
        'employee_uuid' => $lucia,
        'work_date' => '2026-10-06',
        'clocked_in_at' => '2026-10-06T06:00:00Z',
        'clocked_out_at' => '2026-10-06T14:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
    ])->assertStatus(201);
    Auth::forgetGuards();

    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    Api::as($admin)->post('/api/v1/management-accounts/'.$jefe->uuid.'/deactivate', ['reason' => 'Fin de temporada'])
        ->assertStatus(200);
    Auth::forgetGuards();

    return ['admin' => $admin, 'jefe' => $jefe, 'lucia' => $lucia];
}

it('mantiene en la lista como dada de baja la cuenta que se acaba de dar de baja', function (): void {
    $escenario = cuentasEscenarioJefeQueFirmo();

    Api::as($escenario['admin'])->get('/api/v1/management-accounts', ['status' => 'deactivated'])
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.uuid', $escenario['jefe']->uuid)
        ->assertJsonPath('data.0.status', 'deactivated');
})->group('RF-ID-10', 'RL-16');

it('deja lo que firmo la cuenta dada de baja atribuido a ella', function (): void {
    $escenario = cuentasEscenarioJefeQueFirmo();

    Api::as($escenario['admin'])->get('/api/v1/employees/'.$escenario['lucia'].'/workdays', ['from' => '2026-10-06', 'to' => '2026-10-06'])
        ->assertStatus(200)
        ->assertJsonPath('data.0.corrections.0.action', 'created')
        ->assertJsonPath('data.0.corrections.0.performed_by.uuid', $escenario['jefe']->uuid)
        ->assertJsonPath('data.0.corrections.0.performed_by.name', 'Jefe de Recepcion');
})->group('RF-ID-10', 'RN-13', 'RL-04');
