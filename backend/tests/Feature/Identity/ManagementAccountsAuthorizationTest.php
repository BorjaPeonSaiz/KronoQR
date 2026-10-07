<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
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
 * Autorizacion negativa de las cinco rutas de `accounts:*` (**RF-ID-10**,
 * regla dura 18): `admin` y solo `admin`, y **nunca un acceso de soporte**, con
 * ningun alcance (ADR-020, regla dura 16). Ningun rechazo deja cuenta, cambio
 * ni asiento.
 *
 * **La cuenta objetivo existe de verdad**, activa y con segundo factor, para
 * que «no cambia nada» pueda fallar: contra un `uuid` inexistente, una policy
 * abierta tampoco cambiaria nada.
 *
 * La sexta ruta de la pantalla, `POST /auth/password`, la usan los cuatro roles
 * sobre si mismos: sus negativas (soporte, quiosco, portal, sesion pendiente)
 * viven en `OwnPasswordApiTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();

    $objetivo = ManagementUsers::withRole(UserRole::RRHH, 'objetivo@hotel.example');
    $objetivo->uuid = ACCOUNTS_AUTHZ_TARGET;
    $objetivo->save();
    ManagementUsers::withActiveSecondFactor($objetivo);
});

const ACCOUNTS_AUTHZ_TARGET = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91';

/**
 * Las cinco rutas, con un cuerpo valido para que lo que se mida sea la
 * autorizacion y no la validacion.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function rutasDeCuentas(): array
{
    $proof = ['actor_current_password' => ManagementUsers::PASSWORD];

    return [
        ['GET', '/api/v1/management-accounts', []],
        ['POST', '/api/v1/management-accounts', ['name' => 'A', 'email' => 'nueva@hotel.example', 'role' => 'admin', ...$proof]],
        ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/deactivate', ['reason' => 'x']],
        ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/password/reset', ['reason' => 'x', ...$proof]],
        ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/two-factor/reset', ['reason' => 'x', ...$proof]],
    ];
}

function cadaRutaDeCuentasResponde(string $token, int $status): void
{
    foreach (rutasDeCuentas() as [$method, $path, $body]) {
        expect(Api::as($token)->call($method, $path, $body)->status())->toBe($status, $method.' '.$path);
    }

    cuentaObjetivoIntacta();
}

/**
 * Ni alta, ni baja, ni contrasena ni segundo factor retirados, ni asiento de
 * nada de ello.
 */
function cuentaObjetivoIntacta(): void
{
    /** @var object{is_active: bool, password: string, two_factor_confirmed_at: string|null, temporary_password_expires_at: string|null} $objetivo */
    $objetivo = DB::table('users')->where('uuid', ACCOUNTS_AUTHZ_TARGET)->first();

    expect(DB::table('users')->where('email', 'nueva@hotel.example')->exists())->toBeFalse()
        ->and($objetivo->is_active)->toBeTrue()
        ->and(password_verify(ManagementUsers::PASSWORD, $objetivo->password))->toBeTrue()
        ->and($objetivo->temporary_password_expires_at)->toBeNull()
        ->and($objetivo->two_factor_confirmed_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'like', 'user.%')->count())->toBe(0)
        ->and(DB::table('audit_log')->whereIn('action', ['auth.two_factor_reset', 'role_assignment.changed'])->count())->toBe(0);
}

it('no deja entrar sin token', function (): void {
    foreach (rutasDeCuentas() as [$method, $path, $body]) {
        expect(Api::guest()->call($method, $path, $body)->status())->toBe(401);
    }
})->group('RF-ID-10', 'RS-04');

it('rechaza a todo rol de gestion distinto de admin', function (UserRole $role): void {
    cadaRutaDeCuentasResponde(ManagementUsers::tokenFor(ManagementUsers::withRole($role)), 403);
})->with([
    // Gestiona la plantilla, no quien entra al panel.
    'rrhh' => [UserRole::RRHH],
    // Quien vigila no autoriza.
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-ID-10', 'RF-ID-02', 'RQ-07');

it('rechaza los tres alcances de soporte', function (SupportScope $scope): void {
    cadaRutaDeCuentasResponde(SupportGrants::tokenFor($scope), 403);
})->with([
    'diagnostics' => [SupportScope::Diagnostics],
    'read_only' => [SupportScope::ReadOnly],
    'configuration' => [SupportScope::Configuration],
])->group('RF-ID-10', 'RF-PD-11', 'RS-04');

it('rechaza un token de soporte al que alguien le hubiera puesto accounts:* a mano', function (): void {
    // La policy cierra aunque el ambito abra: un acceso de soporte actua como
    // `admin` ante las policies, y crear una cuenta `admin` es como un acceso
    // temporal se vuelve permanente.
    cadaRutaDeCuentasResponde(SupportGrants::tokenWithAbilities(['accounts:*'], SupportScope::Configuration), 403);
})->group('RF-ID-10', 'RF-PD-11', 'RS-04');

it('rechaza el token de un quiosco', function (): void {
    cadaRutaDeCuentasResponde(AttendanceFixtures::scenario()['token'], 403);
})->group('RF-ID-10', 'RS-04');

it('rechaza una sesion de portal', function (): void {
    cadaRutaDeCuentasResponde(PortalLogins::open(WorkforceFixtures::employee(WorkforceFixtures::onlySiteId())), 403);
})->group('RF-ID-10', 'RS-04');

it('rechaza una sesion pendiente de segundo factor', function (): void {
    // Su token solo lleva `2fa:pending`: el middleware `ability` la para.
    cadaRutaDeCuentasResponde(ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::ADMIN)), 403);
})->group('RF-ID-10', 'RS-06');

it('rechaza a una admin con un token emitido antes de que existiera accounts:*', function (): void {
    // Los ambitos se fijan al emitir: tras actualizar, hay que volver a entrar.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $admin->createToken('Panel', ['settings:*', 'employees:*'])->plainTextToken;

    cadaRutaDeCuentasResponde($token, 403);
})->group('RF-ID-10', 'RS-04');

it('rechaza una sesion de contrasena temporal con password-change-required', function (string $method, string $path, array $body): void {
    // La sesion que abre una contrasena temporal solo lleva `password:change`
    // (ADR-051): ni siquiera una admin entra en la pantalla de cuentas hasta
    // fijar la suya.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $admin->createToken('Panel de gestion', [TokenAbility::PASSWORD_CHANGE->value])->plainTextToken;

    Api::as($token)->call($method, $path, $body)
        ->assertStatus(403)
        ->assertJsonPath('type', ProblemDetails::TYPE_PASSWORD_CHANGE_REQUIRED);

    cuentaObjetivoIntacta();
})->with([
    'listado' => ['GET', '/api/v1/management-accounts', []],
    'alta' => ['POST', '/api/v1/management-accounts', ['name' => 'A', 'email' => 'nueva@hotel.example', 'role' => 'admin', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'baja' => ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/deactivate', ['reason' => 'x']],
    'restablecimiento de la contrasena' => ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/password/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'restablecimiento del segundo factor' => ['POST', '/api/v1/management-accounts/'.ACCOUNTS_AUTHZ_TARGET.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
])->group('RF-ID-10', 'RS-04', 'RS-06');
