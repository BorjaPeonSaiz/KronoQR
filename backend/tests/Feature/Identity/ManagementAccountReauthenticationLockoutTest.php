<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El bloqueo de la reautenticacion de quien actua, de punta a punta por HTTP
 * (**RF-ID-10**, RF-ID-01, RS-06, ASVS V3.7.1).
 *
 * `ActorReauthentication` cuenta los fallos en el contador de codigo de la
 * propia cuenta (`2fa|<uuid>`): con el bloqueo abierto, las tres operaciones que
 * piden reautenticacion responden `429` con `Retry-After`, aunque la prueba ya
 * sea la buena, y no hacen nada. La regla esta probada en unitaria; aqui, que el
 * `429` llega al panel con su cabecera, que el bloqueo deja un solo asiento y
 * que alternar rutas no compra intentos.
 */

uses(RefreshDatabase::class);

const REAUTH_LOCKOUT_WRONG = 'No-Es-La-Mia-2026!';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    config()->set('identity.two_factor.required_roles', []);
    config()->set('identity.two_factor.max_attempts', 3);
    config()->set('identity.two_factor.lockout_seconds', 900);
});

it('bloquea con 429 y Retry-After tras tres fallos, aunque la cuarta vez la contrasena sea la buena', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $alta = ['name' => 'Direccion RRHH', 'email' => 'bloqueo@hotel.example', 'role' => 'rrhh'];

    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);
    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);
    Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);

    $bloqueada = Api::as($token)->post('/api/v1/management-accounts', [...$alta, 'actor_current_password' => ManagementUsers::PASSWORD]);

    $bloqueada->assertValidResponse(429);

    expect((int) $bloqueada->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $bloqueada->headers->get('Retry-After'))->toBeLessThanOrEqual(900)
        ->and(DB::table('users')->where('email', 'bloqueo@hotel.example')->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'auth.lockout_started')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'user.created')->count())->toBe(0);
})->group('RF-ID-10', 'RF-ID-01', 'RS-06');

it('no compra intentos alternando la ruta: el bloqueo del alta cierra tambien los restablecimientos', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($rrhh);
    $hashAntes = DB::table('users')->where('id', $rrhh->id)->value('password');

    Api::as($token)->post('/api/v1/management-accounts', ['name' => 'A', 'email' => 'a@hotel.example', 'role' => 'rrhh', 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);
    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/password/reset', ['reason' => 'x', 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);
    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => REAUTH_LOCKOUT_WRONG])->assertStatus(422);

    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/password/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD])
        ->assertStatus(429);
    Api::as($token)->post('/api/v1/management-accounts/'.$rrhh->uuid.'/two-factor/reset', ['reason' => 'x', 'actor_current_password' => ManagementUsers::PASSWORD])
        ->assertStatus(429);

    expect(DB::table('users')->where('id', $rrhh->id)->value('password'))->toBe($hashAntes)
        ->and(DB::table('users')->where('id', $rrhh->id)->value('two_factor_confirmed_at'))->not->toBeNull()
        ->and(DB::table('audit_log')->whereIn('action', ['user.password_reset', 'auth.two_factor_reset'])->count())->toBe(0);
})->group('RF-ID-10', 'RF-ID-01', 'RS-06');
