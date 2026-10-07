<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
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
 * `POST /api/v1/auth/password`: el cambio de la contrasena PROPIA (**RF-ID-10**,
 * RF-ID-01). Cualquier rol de gestion sobre si mismo; ningun otro portador de
 * token.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

const OWN_PASSWORD_NEW = 'Una-Contrasena-Nueva-2026!';

it('cambia la contrasena propia de cada rol de gestion, cierra sus otras sesiones y conserva esta', function (UserRole $role): void {
    $user = ManagementUsers::withRole($role);
    $esta = ManagementUsers::tokenFor($user);
    $otra = ManagementUsers::tokenFor($user);

    Api::as($esta)->post('/api/v1/auth/password', [
        'current_password' => ManagementUsers::PASSWORD,
        'new_password' => OWN_PASSWORD_NEW,
    ])->assertStatus(204);

    Api::as($esta)->get('/api/v1/auth/me')->assertStatus(200);
    Api::as($otra)->get('/api/v1/auth/me')->assertStatus(401);

    $entry = DB::table('audit_log')->where('action', 'user.password_changed')->first();

    expect($entry?->actor_id)->toBe($user->id)
        ->and((string) json_encode($entry))->not->toContain(OWN_PASSWORD_NEW);
})->with([
    'admin' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-ID-10', 'RS-06');

it('responde 422 en el campo que falla, sin cambiar nada', function (array $body, string $field): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($user))->post('/api/v1/auth/password', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field], 'errors');

    expect(DB::table('audit_log')->where('action', 'user.password_changed')->count())->toBe(0);
})->with([
    'actual incorrecta' => [['current_password' => 'No-Es-Esta-1!', 'new_password' => OWN_PASSWORD_NEW], 'current_password'],
    'nueva corta' => [['current_password' => ManagementUsers::PASSWORD, 'new_password' => 'Ab1!'], 'new_password'],
    'nueva sin simbolos' => [['current_password' => ManagementUsers::PASSWORD, 'new_password' => 'SinSimbolos12345'], 'new_password'],
    'nueva de mas de 72 bytes' => [['current_password' => ManagementUsers::PASSWORD, 'new_password' => 'Aa1!'.str_repeat('ñ', 36)], 'new_password'],
    'nueva igual a la actual' => [['current_password' => ManagementUsers::PASSWORD, 'new_password' => ManagementUsers::PASSWORD], 'new_password'],
    'sin la actual' => [['new_password' => OWN_PASSWORD_NEW], 'current_password'],
])->group('RF-ID-10', 'RF-ID-01');

it('bloquea tras los fallos de RF-ID-01 con Retry-After y revoca la sesion con la que se probaba', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);
    $max = config()->integer('identity.login.max_attempts');

    for ($i = 1; $i < $max; $i++) {
        Api::as($token)->post('/api/v1/auth/password', [
            'current_password' => 'Fallo-'.$i.'-Aa!', 'new_password' => OWN_PASSWORD_NEW,
        ])->assertStatus(422);
    }

    $bloqueo = Api::as($token)->post('/api/v1/auth/password', [
        'current_password' => 'Fallo-final-Aa!', 'new_password' => OWN_PASSWORD_NEW,
    ]);

    $bloqueo->assertStatus(429);

    expect($bloqueo->headers->get('Retry-After'))->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'auth.lockout_started')->count())->toBe(1);

    Api::as($token)->get('/api/v1/auth/me')->assertStatus(401);
})->group('RF-ID-10', 'RF-ID-01');

it('no deja cambiar la contrasena a ningun acceso de soporte', function (SupportScope $scope): void {
    Api::as(SupportGrants::tokenFor($scope))->post('/api/v1/auth/password', [
        'current_password' => 'x', 'new_password' => OWN_PASSWORD_NEW,
    ])->assertStatus(403)->assertJsonPath('type', ProblemDetails::TYPE_FORBIDDEN);
})->with([
    'diagnostics' => [SupportScope::Diagnostics],
    'read_only' => [SupportScope::ReadOnly],
    'configuration' => [SupportScope::Configuration],
])->group('RF-ID-10', 'RF-PD-11');

it('no deja cambiarla a un quiosco ni a una sesion de portal', function (): void {
    $body = ['current_password' => 'x', 'new_password' => OWN_PASSWORD_NEW];

    Api::as(AttendanceFixtures::scenario()['token'])->post('/api/v1/auth/password', $body)->assertStatus(403);
    Api::as(PortalLogins::open(WorkforceFixtures::employee(WorkforceFixtures::onlySiteId())))
        ->post('/api/v1/auth/password', $body)->assertStatus(403);
})->group('RF-ID-10', 'RS-04');

it('pide terminar de entrar a una sesion pendiente y credenciales a quien no tiene token', function (): void {
    $body = ['current_password' => ManagementUsers::PASSWORD, 'new_password' => OWN_PASSWORD_NEW];

    Api::guest()->post('/api/v1/auth/password', $body)->assertStatus(401);
    Api::as(ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/auth/password', $body)->assertStatus(401);
})->group('RF-ID-10', 'RS-06');

it('responde 401 a una sesion que un restablecimiento ya cerro', function (): void {
    // El `409` de la carrera (hash cambiado entre la comparacion y la
    // escritura) no se puede provocar por HTTP sin concurrencia real: lo cubre
    // `ChangeOwnPasswordTest` (Unit). Aqui, que una sesion cerrada no fija nada.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);
    DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();

    Api::as($token)->post('/api/v1/auth/password', [
        'current_password' => ManagementUsers::PASSWORD, 'new_password' => OWN_PASSWORD_NEW,
    ])->assertStatus(401);
})->group('RF-ID-10');
