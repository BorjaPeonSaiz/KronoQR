<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `Cache-Control: no-store, private` en todo `/api/v1/auth/*` y en
 * `POST /api/v1/setup/administrator` (2.3.0, bloque 8).
 *
 * El cuerpo de estas respuestas es una credencial —un token de sesion, el
 * secreto TOTP del alta— o la abre. Con el `no-cache, private` por omision de
 * Laravel, el navegador podia guardarla en su cache de disco, y el panel se abre
 * a veces en el ordenador compartido de recepcion. La cabecera la pone el
 * middleware de grupo `no-store` (`PreventResponseCaching`), y aqui se prueba
 * **una ruta por prueba**: si alguien saca una del grupo, falla la suya.
 *
 * Cada respuesta se valida ademas contra el contrato, que declara la cabecera
 * con `components/headers/NoStoreCacheControl`.
 */

uses(RefreshDatabase::class);

const AUTH_NO_STORE_VALUE = 'no-store, private';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    RateLimiter::clear('auth-ip:127.0.0.1');
    config()->set('identity.two_factor.required_roles', ['admin', 'rrhh', 'auditor', 'responsable_departamento']);
});

it('POST /auth/login sin segundo factor responde con no-store', function (): void {
    // Un rol sin 2FA obligatorio recibe la sesion completa en el 200.
    config()->set('identity.two_factor.required_roles', []);
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::guest()->post('/api/v1/auth/login', ['email' => $user->email, 'password' => ManagementUsers::PASSWORD])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-01', 'RS-09');

it('POST /auth/login con segundo factor pendiente responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($user);

    Api::guest()->post('/api/v1/auth/login', ['email' => $user->email, 'password' => ManagementUsers::PASSWORD])
        ->assertValidResponse(202)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-01', 'RS-06', 'RS-09');

it('un rechazo de POST /auth/login tambien sale con no-store', function (): void {
    // El middleware es de grupo: vale tambien para los errores, que no llevan
    // secretos pero no tienen nada que ganar en una cache.
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::guest()->post('/api/v1/auth/login', ['email' => $user->email, 'password' => 'No-Es-La-Buena-1!'])
        ->assertStatus(401)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-01', 'RS-09');

it('POST /auth/2fa/verify responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secret = ManagementUsers::withActiveSecondFactor($user);
    $pending = ManagementUsers::pendingTokenFor($user);

    Api::as($pending)->post('/api/v1/auth/2fa/verify', ['code' => ManagementUsers::totpCodeFor($secret)])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RS-06', 'RS-09');

it('POST /auth/2fa/enrol, que lleva el secreto TOTP, responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::pendingTokenFor($user))->post('/api/v1/auth/2fa/enrol')
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RS-06', 'RS-09');

it('POST /auth/2fa/confirm responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $pending = ManagementUsers::pendingTokenFor($user);

    $secret = Api::as($pending)->post('/api/v1/auth/2fa/enrol')->assertValidResponse(200)->json('secret');
    expect($secret)->toBeString();

    Auth::forgetGuards();

    Api::as($pending)->post('/api/v1/auth/2fa/confirm', [
        'code' => ManagementUsers::totpCodeFor(\is_string($secret) ? $secret : ''),
    ])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RS-06', 'RS-09');

it('POST /auth/logout responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($user))->post('/api/v1/auth/logout')
        ->assertValidResponse(204)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-01', 'RS-09');

it('GET /auth/me responde con no-store', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($user))->get('/api/v1/auth/me')
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-01', 'RS-09');

it('POST /auth/password responde con no-store', function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($user))->post('/api/v1/auth/password', [
        'current_password' => ManagementUsers::PASSWORD,
        'new_password' => 'Una-Contrasena-Nueva-2026!',
    ])
        ->assertValidResponse(204)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-ID-10', 'RS-09');

it('POST /setup/administrator responde con no-store', function (): void {
    Api::guest()->post('/api/v1/setup/administrator', [
        'name' => 'Direccion del hotel',
        'email' => 'direccion@hotel.example',
        'password' => 'Una-Contrasena-Larga-1!',
        'device_name' => 'Panel de gestion',
    ])
        ->assertValidResponse(201)
        ->assertHeader('Cache-Control', AUTH_NO_STORE_VALUE);
})->group('RF-PD-03', 'RS-09');
