<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Cada respuesta de las cuentas de gestion, contra `openapi.yaml` (**RF-ID-10**,
 * ADR-051, regla 2 de autoridad del CLAUDE.md): el `201` del alta, el `200`
 * `TemporaryPasswordIssued`, el `204` del cambio propio y los problem+json
 * `403` (prohibido y `password-change-required`), `404`, `409` y `422` (la
 * reautenticacion de quien actua) de las seis rutas.
 *
 * Lo que dicen las respuestas lo prueban `ManagementAccountsApiTest` y
 * `OwnPasswordApiTest`; aqui, solo que su forma es la que el contrato promete al
 * panel, que genera sus tipos de ese fichero.
 */

uses(RefreshDatabase::class);

const MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    config()->set('identity.two_factor.required_roles', []);
});

function tokenDeAdminDelContrato(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('cumple el contrato en el listado', function (): void {
    ManagementUsers::withRole(UserRole::RRHH);

    Api::as(tokenDeAdminDelContrato())->get('/api/v1/management-accounts', ['per_page' => 10])
        ->assertValidRequest()
        ->assertValidResponse(200);
})->group('RF-ID-10', 'RQ-06');

it('cumple el contrato en el alta, en su 409 y en el 422 de la reautenticacion', function (): void {
    $token = tokenDeAdminDelContrato();
    $alta = ['name' => 'Direccion RRHH', 'email' => 'contrato@hotel.example', 'role' => 'rrhh', 'locale' => 'es'];

    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => ManagementUsers::PASSWORD])
        ->assertValidRequest()
        ->assertValidResponse(201);

    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => ManagementUsers::PASSWORD])
        ->assertValidResponse(409)
        ->assertJsonPath('type', ProblemDetails::TYPE_CONFLICT);

    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'email' => 'otra@hotel.example', 'actor_current_password' => 'No-Es-La-Mia-1!'])
        ->assertValidResponse(422)
        ->assertJsonValidationErrors(['actor_current_password'], 'errors');
})->group('RF-ID-10', 'RQ-06');

it('cumple el contrato en la baja, su 404 y su 409', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($admin);
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/deactivate', ['reason' => 'Deja el hotel'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    Api::as($token)->post('/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/deactivate', ['reason' => 'Baja'])
        ->assertValidResponse(404);

    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/deactivate', ['reason' => 'Me voy'])
        ->assertValidResponse(409);
})->group('RF-ID-10', 'RQ-06');

it('cumple el contrato en el restablecimiento de la contrasena: TemporaryPasswordIssued, 404, 409 y 422', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($admin);
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $prueba = ['reason' => 'Olvido', 'actor_current_password' => ManagementUsers::PASSWORD];

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/password/reset', $prueba)
        ->assertValidRequest()
        ->assertValidResponse(200);

    Api::as($token)->post('/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/password/reset', $prueba)
        ->assertValidResponse(404);

    Api::as($token)->post('/api/v1/management-accounts/'.$admin->uuid.'/password/reset', $prueba)
        ->assertValidResponse(409);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/password/reset', ['reason' => 'Olvido', 'actor_current_password' => 'No-Es-La-Mia-1!'])
        ->assertValidResponse(422);
})->group('RF-ID-10', 'RQ-06');

it('cumple el contrato en el restablecimiento del segundo factor, su 404, su 409 y su 422', function (): void {
    $token = tokenDeAdminDelContrato();
    $conSegundoFactor = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($conSegundoFactor);
    $sinSegundoFactor = ManagementUsers::withRole(UserRole::AUDITOR);
    $prueba = ['reason' => 'Telefono extraviado', 'actor_current_password' => ManagementUsers::PASSWORD];

    Api::as($token)->post('/api/v1/management-accounts/'.$conSegundoFactor->uuid.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => 'No-Es-La-Mia-1!'])
        ->assertValidResponse(422);

    Api::as($token)->post('/api/v1/management-accounts/'.$conSegundoFactor->uuid.'/two-factor/reset', $prueba)
        ->assertValidRequest()
        ->assertValidResponse(200);

    Api::as($token)->post('/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/two-factor/reset', $prueba)
        ->assertValidResponse(404);

    Api::as($token)->post('/api/v1/management-accounts/'.$sinSegundoFactor->uuid.'/two-factor/reset', $prueba)
        ->assertValidResponse(409);
})->group('RF-ID-10', 'RQ-06');

it('cumple el contrato en el 403 de un rol sin permiso', function (string $method, string $path, array $body): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))->call($method, $path, $body)
        ->assertValidResponse(403)
        ->assertJsonPath('type', ProblemDetails::TYPE_FORBIDDEN);
})->with([
    'listado' => ['GET', '/api/v1/management-accounts', []],
    'alta' => ['POST', '/api/v1/management-accounts', ['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'baja' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/deactivate', ['reason' => 'x']],
    'restablecimiento de la contrasena' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/password/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'restablecimiento del segundo factor' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
])->group('RF-ID-10', 'RQ-06', 'RQ-07');

it('cumple el contrato en el 403 password-change-required', function (string $method, string $path, array $body): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $admin->createToken('Panel de gestion', [TokenAbility::PASSWORD_CHANGE->value])->plainTextToken;

    Api::as($token)->call($method, $path, $body)
        ->assertValidResponse(403)
        ->assertJsonPath('type', ProblemDetails::TYPE_PASSWORD_CHANGE_REQUIRED);
})->with([
    'listado' => ['GET', '/api/v1/management-accounts', []],
    'alta' => ['POST', '/api/v1/management-accounts', ['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'baja' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/deactivate', ['reason' => 'x']],
    'restablecimiento de la contrasena' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/password/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
    'restablecimiento del segundo factor' => ['POST', '/api/v1/management-accounts/'.MANAGEMENT_ACCOUNTS_CONTRACT_UNKNOWN.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD]],
])->group('RF-ID-10', 'RQ-06', 'RS-04');

it('cumple el contrato en el cambio de la contrasena propia: 204, 422 y 403', function (): void {
    $sesion = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($sesion)->post('/api/v1/auth/password', ['current_password' => 'No-Es-Esta-1!', 'new_password' => 'Una-Contrasena-Nueva-2026!'])
        ->assertValidResponse(422);

    Api::as($sesion)->post('/api/v1/auth/password', ['current_password' => ManagementUsers::PASSWORD, 'new_password' => 'Una-Contrasena-Nueva-2026!'])
        ->assertValidRequest()
        ->assertValidResponse(204);

    Auth::forgetGuards();

    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->post('/api/v1/auth/password', ['current_password' => 'x', 'new_password' => 'Una-Contrasena-Nueva-2026!'])
        ->assertValidResponse(403);
})->group('RF-ID-10', 'RQ-06');
