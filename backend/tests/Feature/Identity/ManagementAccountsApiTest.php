<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las cuentas de gestion desde el panel (**RF-ID-10**, ADR-051, bloque 12c):
 * `GET`/`POST /management-accounts` y la baja y los restablecimientos de
 * `/management-accounts/{uuid}/*`, de punta a punta por HTTP.
 *
 * La autorizacion negativa por cada rol vive en
 * `ManagementAccountsAuthorizationTest`; el cambio de la contrasena propia, en
 * `OwnPasswordApiTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();

    // Los accesos de estas pruebas no resuelven un TOTP salvo donde se dice:
    // la lista de roles obligados es configuracion (regla dura 13).
    config()->set('identity.two_factor.required_roles', []);
    RateLimiter::clear('auth-ip:127.0.0.1');
});

/**
 * Una `admin` sin segundo factor (se reautentica con su contrasena) y su sesion.
 *
 * @return array{0: User, 1: string}
 */
function adminDeCuentas(): array
{
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    return [$admin, ManagementUsers::tokenFor($admin)];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function conReautenticacion(array $extra = []): array
{
    return [...$extra, 'actor_current_password' => ManagementUsers::PASSWORD];
}

/**
 * @return list<string>
 */
function accionesDeAuditoria(): array
{
    $actions = [];

    foreach (DB::table('audit_log')->orderBy('id')->pluck('action') as $action) {
        $actions[] = \is_string($action) ? $action : '';
    }

    return $actions;
}

/**
 * Un campo de texto de una respuesta JSON, o `''`.
 *
 * @param  TestResponse<Response>  $response
 */
function textoJson(TestResponse $response, string $path): string
{
    $value = $response->json($path);

    return \is_scalar($value) ? (string) $value : '';
}

it('da de alta una cuenta con contrasena temporal de un solo uso, sin cache y con sus dos asientos', function (): void {
    [$admin, $token] = adminDeCuentas();

    $response = Api::as($token)->post('/api/v1/management-accounts', conReautenticacion([
        'name' => 'Direccion RRHH',
        'email' => 'rrhh@hotel.example',
        'role' => 'rrhh',
        'locale' => 'es',
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('account.email', 'rrhh@hotel.example')
        ->assertJsonPath('account.roles', ['rrhh'])
        ->assertJsonPath('account.status', 'active')
        ->assertJsonPath('account.password_status', 'temporary')
        ->assertJsonPath('account.two_factor_enabled', false);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');

    $password = textoJson($response, 'temporary_password.password');
    $uuid = textoJson($response, 'account.uuid');

    expect(strlen($password))->toBeGreaterThanOrEqual(20)
        ->and($response->json('temporary_password.account_uuid'))->toBe($uuid);

    $entries = DB::table('audit_log')
        ->whereIn('action', [AuditAction::ManagementAccountCreated->value, AuditAction::RoleAssignmentChanged->value])
        ->orderBy('id')
        ->get();

    expect($entries->pluck('action')->all())->toBe(['user.created', 'role_assignment.changed']);

    foreach ($entries as $entry) {
        $raw = (string) json_encode($entry);

        expect($raw)->toContain($uuid)
            ->and($raw)->not->toContain('rrhh@hotel.example')
            ->and($raw)->not->toContain('Direccion RRHH')
            ->and($raw)->not->toContain($password)
            ->and($entry->actor_id)->toBe($admin->id);
    }

    // La temporal no vuelve en ninguna consulta posterior.
    expect((string) Api::as($token)->get('/api/v1/management-accounts')->getContent())->not->toContain($password);
})->group('RF-ID-10', 'RF-ID-02', 'RS-05');

it('rechaza con 409 un correo ya usado, tambien por una cuenta dada de baja', function (): void {
    [, $token] = adminDeCuentas();
    $baja = ManagementUsers::withRole(UserRole::AUDITOR, 'antigua@hotel.example');
    $baja->is_active = false;
    $baja->save();

    Api::as($token)->post('/api/v1/management-accounts', conReautenticacion([
        'name' => 'Otra persona', 'email' => 'ANTIGUA@hotel.example', 'role' => 'auditor',
    ]))->assertStatus(409)->assertJsonPath('type', ProblemDetails::TYPE_CONFLICT);

    expect(accionesDeAuditoria())->not->toContain('user.created');
})->group('RF-ID-10');

it('valida cada campo del alta y la reautenticacion de quien la hace', function (array $body, string $field): void {
    [, $token] = adminDeCuentas();

    Api::as($token)->post('/api/v1/management-accounts', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field], 'errors');

    expect(User::query()->count())->toBe(1);
})->with([
    'sin nombre' => [['email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => ManagementUsers::PASSWORD], 'name'],
    'correo no valido' => [['name' => 'A', 'email' => 'no-es-correo', 'role' => 'rrhh', 'actor_current_password' => ManagementUsers::PASSWORD], 'email'],
    'rol que no es de gestion' => [['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'kiosk', 'actor_current_password' => ManagementUsers::PASSWORD], 'role'],
    'idioma no activo' => [['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'locale' => 'xx', 'actor_current_password' => ManagementUsers::PASSWORD], 'locale'],
    'sin reautenticacion' => [['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh'], 'actor_totp_code'],
    'contrasena de quien actua incorrecta' => [['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => 'mala'], 'actor_current_password'],
    'campo que no existe' => [['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'password' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD], 'password'],
])->group('RF-ID-10', 'RQ-06');

it('exige el codigo del autenticador a una admin con segundo factor, y no lo acepta dos veces', function (): void {
    [$admin, $token] = adminDeCuentas();
    $secret = ManagementUsers::withActiveSecondFactor($admin);
    $code = ManagementUsers::totpCodeFor($secret);

    Api::as($token)->post('/api/v1/management-accounts', [
        'name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => ManagementUsers::PASSWORD,
    ])->assertStatus(422)->assertJsonValidationErrors(['actor_current_password'], 'errors');

    Api::as($token)->post('/api/v1/management-accounts', [
        'name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_totp_code' => $code,
    ])->assertStatus(201);

    Api::as($token)->post('/api/v1/management-accounts', [
        'name' => 'B', 'email' => 'b@hotel.example', 'role' => 'rrhh', 'actor_totp_code' => $code,
    ])->assertStatus(422)->assertJsonValidationErrors(['actor_totp_code'], 'errors');
})->group('RF-ID-10', 'RS-06');

it('lista activas primero y por nombre, con filtros, paginacion y sin secretos', function (): void {
    [, $token] = adminDeCuentas();
    $zeta = ManagementUsers::withRole(UserRole::RRHH, 'zeta@hotel.example');
    $zeta->name = 'Zeta Recepción';
    $zeta->save();
    $alfa = ManagementUsers::withRole(UserRole::AUDITOR, 'alfa@hotel.example');
    $alfa->name = 'Alfa Auditoría';
    $alfa->save();
    $baja = ManagementUsers::withRole(UserRole::RRHH, 'baja@hotel.example');
    $baja->name = 'Aaa Baja';
    $baja->is_active = false;
    $baja->save();
    // Un quiosco tiene fila en `users` y no es una cuenta de gestion.
    ManagementUsers::withRole(UserRole::KIOSK);

    $page = Api::as($token)->get('/api/v1/management-accounts', ['per_page' => 50])->assertStatus(200);

    $names = array_column((array) $page->json('data'), 'name');

    expect($page->json('meta.total'))->toBe(4)
        ->and(end($names))->toBe('Aaa Baja')
        ->and((int) array_search('Alfa Auditoría', $names, true))->toBeLessThan((int) array_search('Zeta Recepción', $names, true))
        ->and((string) $page->getContent())->not->toContain('password"')
        ->and((string) $page->getContent())->not->toContain('two_factor_secret');

    expect(Api::as($token)->get('/api/v1/management-accounts', ['q' => 'recepcion'])->json('meta.total'))->toBe(1)
        ->and(Api::as($token)->get('/api/v1/management-accounts', ['q' => '%'])->json('meta.total'))->toBe(0)
        ->and(Api::as($token)->get('/api/v1/management-accounts', ['status' => 'deactivated'])->json('data.0.email'))->toBe('baja@hotel.example')
        ->and(Api::as($token)->get('/api/v1/management-accounts', ['role' => 'auditor'])->json('meta.total'))->toBe(1)
        ->and(Api::as($token)->get('/api/v1/management-accounts', ['per_page' => 1, 'page' => 2])->json('meta.total_pages'))->toBe(4);

    Api::as($token)->get('/api/v1/management-accounts', ['status' => 'borrada'])->assertStatus(422);
})->group('RF-ID-10', 'RF-ID-02');

it('da de baja, cierra sus sesiones y responde con la cuenta ya de baja', function (): void {
    [$admin, $token] = adminDeCuentas();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $sesion = ManagementUsers::tokenFor($rrhh);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/deactivate', ['reason' => 'Deja el hotel'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'deactivated')
        ->assertJsonPath('uuid', $rrhh->uuid);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(401);

    $entry = DB::table('audit_log')->where('action', 'user.deactivated')->first();

    expect((string) json_encode($entry))->toContain('Deja el hotel')
        ->and($entry?->actor_id)->toBe($admin->id);
})->group('RF-ID-10', 'RS-05', 'RL-16');

it('responde el mismo 404 a una cuenta inexistente y a una ya de baja, sin asiento', function (): void {
    [, $token] = adminDeCuentas();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/deactivate', ['reason' => 'Baja'])->assertStatus(200);

    $deNuevo = Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/deactivate', ['reason' => 'Baja']);
    $inexistente = Api::as($token)->post('/api/v1/management-accounts/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99/deactivate', ['reason' => 'Baja']);

    $deNuevo->assertStatus(404);
    $inexistente->assertStatus(404);

    expect($deNuevo->json())->toBe($inexistente->json())
        ->and(DB::table('audit_log')->where('action', 'user.deactivated')->count())->toBe(1);
})->group('RF-ID-10', 'RS-03', 'RS-05');

it('no deja darse de baja a si misma ni deja la instalacion sin admin', function (): void {
    [$admin, $token] = adminDeCuentas();

    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/deactivate', ['reason' => 'Me voy'])
        ->assertStatus(409)
        ->assertJsonPath('type', ProblemDetails::TYPE_CONFLICT);

    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/deactivate', [])->assertStatus(422);

    expect(User::query()->where('uuid', $admin->uuid)->value('is_active'))->toBeTrue()
        ->and(accionesDeAuditoria())->not->toContain('user.deactivated');
})->group('RF-ID-10', 'RS-05');

it('retira los accesos de soporte vigentes que concedio la cuenta dada de baja', function (): void {
    [, $token] = adminDeCuentas();
    $otraAdmin = ManagementUsers::withRole(UserRole::ADMIN);
    $grant = SupportGrants::issue(grantedByUserId: $otraAdmin->id);

    Api::as($grant->token)->get('/api/v1/diagnostics/errors')->assertStatus(200);

    Api::as($token)->post('/api/v1/management-accounts/'.$otraAdmin->uuid.'/deactivate', ['reason' => 'Fin de contrato'])
        ->assertStatus(200);

    expect(DB::table('support_grants')->where('uuid', $grant->grant->uuid)->value('revoked_at'))->not->toBeNull();

    Api::as($grant->token)->get('/api/v1/diagnostics/errors')->assertStatus(401);
})->group('RF-ID-10', 'RF-PD-11');

it('restablece la contrasena de otra cuenta con una temporal, sin cache, con motivo y cerrando sus sesiones', function (): void {
    [, $token] = adminDeCuentas();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH, 'jefa@hotel.example');
    $sesion = ManagementUsers::tokenFor($rrhh);

    $response = Api::as($token)->post(
        '/api/v1/management-accounts/'.$rrhh->uuid.'/password/reset',
        conReautenticacion(['reason' => 'Olvido tras las vacaciones']),
    )->assertStatus(200)->assertJsonPath('account_uuid', $rrhh->uuid);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(401);

    $entry = DB::table('audit_log')->where('action', 'user.password_reset')->first();

    expect((string) json_encode($entry))->toContain('Olvido tras las vacaciones')
        ->and((string) json_encode($entry))->not->toContain(textoJson($response, 'password'));

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'jefa@hotel.example', 'password' => textoJson($response, 'password'), 'device_name' => 'Panel',
    ])->assertStatus(200);
})->group('RF-ID-10', 'RS-06', 'RL-16');

it('no restablece la contrasena propia, ni la de una baja, ni sin motivo', function (): void {
    [$admin, $token] = adminDeCuentas();
    $baja = ManagementUsers::withRole(UserRole::RRHH);
    $baja->is_active = false;
    $baja->save();

    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/password/reset', conReautenticacion(['reason' => 'x']))->assertStatus(409);
    Api::as($token)->post('/api/v1/management-accounts/'.$baja->uuid.'/password/reset', conReautenticacion(['reason' => 'x']))->assertStatus(404);
    Api::as($token)->post('/api/v1/management-accounts/'.$baja->uuid.'/password/reset', conReautenticacion())->assertStatus(422);

    expect(accionesDeAuditoria())->not->toContain('user.password_reset');
})->group('RF-ID-10', 'RS-06');

it('retira el segundo factor de otra cuenta y responde con la cuenta sin el', function (): void {
    [, $token] = adminDeCuentas();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($rrhh);
    $sesion = ManagementUsers::tokenFor($rrhh);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/two-factor/reset', conReautenticacion(['reason' => 'Telefono extraviado']))
        ->assertStatus(200)
        ->assertJsonPath('two_factor_enabled', false);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(401);

    expect(accionesDeAuditoria())->toContain('auth.two_factor_reset');
})->group('RF-ID-10', 'RS-06');

it('no retira un segundo factor que no existe, ni el propio, sin asiento', function (): void {
    [$admin, $token] = adminDeCuentas();
    $sinSegundoFactor = ManagementUsers::withRole(UserRole::AUDITOR);

    Api::as($token)->post('/api/v1/management-accounts/'.$sinSegundoFactor->uuid.'/two-factor/reset', conReautenticacion(['reason' => 'x']))->assertStatus(409);
    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/two-factor/reset', conReautenticacion(['reason' => 'x']))->assertStatus(409);
    Api::as($token)->post('/api/v1/management-accounts/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99/two-factor/reset', conReautenticacion(['reason' => 'x']))->assertStatus(404);

    expect(accionesDeAuditoria())->not->toContain('auth.two_factor_reset');
})->group('RF-ID-10', 'RS-06');

it('lleva una cuenta nueva por el recorrido entero de la contrasena temporal', function (): void {
    [, $token] = adminDeCuentas();

    $alta = Api::as($token)->post('/api/v1/management-accounts', conReautenticacion([
        'name' => 'Direccion RRHH', 'email' => 'nueva@hotel.example', 'role' => 'rrhh',
    ]))->assertStatus(201);

    $temporal = textoJson($alta, 'temporary_password.password');

    $session = textoJson(Api::guest()->post('/api/v1/auth/login', [
        'email' => 'nueva@hotel.example', 'password' => $temporal, 'device_name' => 'Panel',
    ])->assertStatus(200), 'token');

    Api::as($session)->get('/api/v1/auth/me')->assertStatus(200)->assertJsonPath('password_change_required', true);
    Api::as($session)->get('/api/v1/employees')
        ->assertStatus(403)
        ->assertJsonPath('type', ProblemDetails::TYPE_PASSWORD_CHANGE_REQUIRED);

    Api::as($session)->post('/api/v1/auth/password', [
        'current_password' => $temporal,
        'new_password' => 'Mi-Contrasena-Propia-2026!',
    ])->assertStatus(204);

    // La MISMA sesion sigue y ya lleva los ambitos de su rol: sin volver a entrar.
    Api::as($session)->get('/api/v1/auth/me')->assertStatus(200)->assertJsonPath('password_change_required', false);
    Api::as($session)->get('/api/v1/employees')->assertStatus(200);

    expect(accionesDeAuditoria())->toContain('user.password_changed');
})->group('RF-ID-10', 'RF-ID-01');

it('responde a una temporal caducada lo mismo que a una contrasena incorrecta', function (): void {
    [, $token] = adminDeCuentas();

    $alta = Api::as($token)->post('/api/v1/management-accounts', conReautenticacion([
        'name' => 'A', 'email' => 'caducada@hotel.example', 'role' => 'auditor',
    ]))->assertStatus(201);

    DB::table('users')->where('email', 'caducada@hotel.example')
        ->update(['temporary_password_expires_at' => now()->subMinute()]);

    $caducada = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'caducada@hotel.example', 'password' => textoJson($alta, 'temporary_password.password'), 'device_name' => 'Panel',
    ]);
    RateLimiter::clear('auth-ip:127.0.0.1');
    $incorrecta = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'caducada@hotel.example', 'password' => 'No-Es-La-Buena-1!', 'device_name' => 'Panel',
    ]);

    $caducada->assertStatus(401);

    expect($caducada->json())->toBe($incorrecta->json())
        ->and(Api::as($token)->get('/api/v1/management-accounts', ['q' => 'caducada'])->json('data.0.password_status'))
        ->toBe('temporary_expired');
})->group('RF-ID-10', 'RS-03');
