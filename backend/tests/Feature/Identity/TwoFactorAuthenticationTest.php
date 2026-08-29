<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * Segundo factor obligatorio de las cuentas de gestion (**RS-06**, RF-ID-01,
 * tarea 2.1).
 *
 * Feature **y contrato a la vez**: cada respuesta se valida contra
 * `docs/api/openapi.yaml` con Spectator. Una prueba que solo mirara campos
 * sueltos dejaria pasar el caso que rompe a los tres frontends —un campo de mas,
 * un tipo cambiado—, porque el cliente TypeScript se genera del contrato.
 *
 * **El TOTP no se escribe a mano.** Los codigos se calculan con la misma libreria
 * que verifica el servidor (`ManagementUsers::totpCodeFor()`): una prueba que
 * pusiera seis digitos literales estaria comprobando su propia aritmetica y se
 * rompería cada treinta segundos.
 *
 * **Nada de esto alcanza al empleado** (reglas duras 11 y 12): su credencial es
 * una tarjeta fisica y su acceso al portal es codigo y PIN. Aqui solo hay cuentas
 * de `users`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El limitador de la zona `auth` (5 r/m) cuenta por origen y su contador vive
    // en la cache: se limpia entre pruebas para que una no herede el cupo gastado
    // por otra. El bloqueo por intentos de codigo es otro control y tiene su
    // propia prueba.
    RateLimiter::clear('auth-ip:127.0.0.1');

    config()->set('identity.two_factor.required_roles', ['admin', 'rrhh', 'auditor']);
});

/**
 * Abre un reto de segundo factor como lo haria el panel: contrasena correcta,
 * `202`, y el `challenge_token` que devuelve.
 */
function abrirReto(string $email): string
{
    $challenge = Api::guest()->post('/api/v1/auth/login', [
        'email' => $email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(202)->json('challenge_token');

    expect($challenge)->toBeString();

    return \is_string($challenge) ? $challenge : '';
}

it('canjea el reto por una sesion cuando el codigo es correcto', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secret = ManagementUsers::withActiveSecondFactor($user);

    $challenge = abrirReto($user->email);

    Auth::forgetGuards();

    $response = Api::as($challenge)->post('/api/v1/auth/2fa/verify', [
        'code' => ManagementUsers::totpCodeFor($secret),
    ]);

    $response->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.uuid', $user->uuid)
        ->assertJsonPath('user.scope.kind', 'all');

    // La sesion que sale de aqui SI lleva los ambitos del rol, al contrario que
    // el reto.
    expect($response->json('user.abilities'))->toContain(TokenAbility::EMPLOYEES_ALL->value);

    // Y ahora si ha entrado alguien: es el punto donde ADR-039 pone
    // `auth.login_succeeded` y `last_login_at`.
    expect(DB::table('users')->where('uuid', $user->uuid)->value('last_login_at'))->not->toBeNull();
})->group('RF-ID-01', 'RS-06', 'RS-13');

it('deja el reto inservible en cuanto se canjea', function (): void {
    // Media autenticacion no puede quedar viva despues de completarse: un reto
    // robado seguiria sirviendo para pedir otra sesion con el codigo siguiente.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secret = ManagementUsers::withActiveSecondFactor($user);

    $challenge = abrirReto($user->email);

    Auth::forgetGuards();

    Api::as($challenge)->post('/api/v1/auth/2fa/verify', [
        'code' => ManagementUsers::totpCodeFor($secret),
    ])->assertValidResponse(200);

    Auth::forgetGuards();

    Api::as($challenge)->post('/api/v1/auth/2fa/verify', ['code' => '000000'])->assertStatus(401);
})->group('RF-ID-01', 'RS-06');

it('rechaza dos veces el mismo codigo aunque siga vigente', function (): void {
    // La unica proteccion contra reenvio que tiene TOTP. Sin ella, un codigo
    // visto por encima del hombro sirve durante el minuto siguiente.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secret = ManagementUsers::withActiveSecondFactor($user);
    $code = ManagementUsers::totpCodeFor($secret);

    $primero = abrirReto($user->email);
    Auth::forgetGuards();

    Api::as($primero)->post('/api/v1/auth/2fa/verify', ['code' => $code])->assertValidResponse(200);

    RateLimiter::clear('auth-ip:127.0.0.1');
    Auth::forgetGuards();

    $segundo = abrirReto($user->email);
    Auth::forgetGuards();

    Api::as($segundo)->post('/api/v1/auth/2fa/verify', ['code' => $code])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');
})->group('RF-ID-01', 'RS-06');

it('responde lo mismo ante un codigo equivocado y una cuenta sin segundo factor', function (): void {
    // Los rechazos no distinguen la causa: hacerlo diria a quien prueba si
    // merece la pena seguir con esa cuenta.
    $conTotp = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($conTotp);

    $sinTotp = ManagementUsers::withRole(UserRole::AUDITOR);

    Api::as(ManagementUsers::pendingTokenFor($conTotp))
        ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');

    Auth::forgetGuards();

    Api::as(ManagementUsers::pendingTokenFor($sinTotp))
        ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');
})->group('RF-ID-01', 'RS-06');

it('bloquea tras los intentos de codigo configurados, con su contador propio', function (): void {
    // Contador distinto del de la contrasena (§7.5 aplicado al segundo factor):
    // gastar el cupo probando codigos no puede dejar a nadie sin poder
    // reintentar su contrasena, ni al reves.
    config()->set('identity.two_factor.max_attempts', 2);

    $user = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($user);
    $pending = ManagementUsers::pendingTokenFor($user);

    foreach (range(1, 2) as $intento) {
        Auth::forgetGuards();
        Api::as($pending)->post('/api/v1/auth/2fa/verify', ['code' => '000000'])->assertStatus(401);
    }

    Auth::forgetGuards();

    Api::as($pending)->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RF-ID-01', 'RS-06', 'RS-13');

it('da de alta el segundo factor y lo activa con el primer codigo', function (): void {
    // El alta completa de RS-06: sin esto, una cuenta nueva de RRHH no tiene
    // ninguna forma de obtener su segundo factor y por tanto ninguna de entrar.
    $user = ManagementUsers::withRole(UserRole::RRHH);

    $challenge = abrirReto($user->email);
    Auth::forgetGuards();

    $alta = Api::as($challenge)->post('/api/v1/auth/2fa/enrol');

    $alta->assertValidResponse(200);

    $secret = $alta->json('secret');
    expect($secret)->toBeString()
        ->and($alta->json('otpauth_uri'))->toStartWith('otpauth://totp/');

    // Sin confirmar todavia: el secreto existe y no autoriza nada.
    expect(DB::table('users')->where('uuid', $user->uuid)->value('two_factor_confirmed_at'))->toBeNull();

    Auth::forgetGuards();

    Api::as($challenge)->post('/api/v1/auth/2fa/confirm', [
        'code' => ManagementUsers::totpCodeFor(\is_string($secret) ? $secret : ''),
    ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('user.uuid', $user->uuid);

    expect(DB::table('users')->where('uuid', $user->uuid)->value('two_factor_confirmed_at'))->not->toBeNull();
})->group('RF-ID-01', 'RS-06');

it('deja el alta repetible mientras no se confirme y cerrada cuando ya lo esta', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $pending = ManagementUsers::pendingTokenFor($user);

    $primero = Api::as($pending)->post('/api/v1/auth/2fa/enrol')->assertValidResponse(200)->json('secret');

    Auth::forgetGuards();

    // Quien cierra la pantalla del QR sin escanearlo repite el alta y recibe otro
    // secreto: el anterior se pierde sin consecuencias.
    $segundo = Api::as($pending)->post('/api/v1/auth/2fa/enrol')->assertValidResponse(200)->json('secret');

    expect($segundo)->not->toBe($primero);

    ManagementUsers::withActiveSecondFactor($user);
    Auth::forgetGuards();

    // Con uno ya activo, no: reemplazarlo es un acto de administracion
    // (`identity:2fa-reset`), no algo que se haga con una sesion pendiente.
    Api::as($pending)->post('/api/v1/auth/2fa/enrol')->assertStatus(409);
})->group('RF-ID-01', 'RS-06');

it('rechaza confirmar sin haber dado de alta nada', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::pendingTokenFor($user))
        ->post('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertStatus(409);
})->group('RF-ID-01', 'RS-06');

it('escribe en audit_log la activacion del segundo factor y nunca el secreto', function (): void {
    // Regla dura 6: activar un segundo factor es emitir una credencial de acceso.
    // Y regla dura 21: lo que se audita es que se activo, no cual.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $pending = ManagementUsers::pendingTokenFor($user);

    $secret = Api::as($pending)->post('/api/v1/auth/2fa/enrol')->assertStatus(200)->json('secret');

    Auth::forgetGuards();

    Api::as($pending)->post('/api/v1/auth/2fa/confirm', [
        'code' => ManagementUsers::totpCodeFor(\is_string($secret) ? $secret : ''),
    ])->assertStatus(200);

    $entry = DB::table('audit_log')
        ->where('action', AuditAction::TwoFactorEnabled->value)
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull();

    $payload = json_encode($entry);

    expect($payload)->toBeString()
        ->and((string) $payload)->toContain($user->uuid)
        ->and((string) $payload)->not->toContain(\is_string($secret) ? $secret : 'imposible')
        ->and((string) $payload)->not->toContain($user->email);
})->group('RS-06', 'RS-05', 'RF-ID-01');

it('deniega los tres endpoints de segundo factor a un token de quiosco', function (string $uri): void {
    // RS-04: el token de una tablet lleva `scan:write`, `roster:read` y
    // `heartbeat:write`. Ninguno abre esta puerta, y si la abriera, quien robase
    // la tablet podria canjearla por una sesion de gestion.
    Api::as(ManagementUsers::kioskToken())->post($uri, ['code' => '000000'])->assertStatus(403);
})->with([
    'verificar' => '/api/v1/auth/2fa/verify',
    'dar de alta' => '/api/v1/auth/2fa/enrol',
    'confirmar' => '/api/v1/auth/2fa/confirm',
])->group('RS-04', 'RS-06');

it('deniega los tres endpoints de segundo factor a una sesion completa', function (string $uri): void {
    // Una sesion ya emitida no lleva `2fa:pending`: no puede volver a pasar por
    // el reto ni pedirse un secreto nuevo sin cerrar sesion.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post($uri, ['code' => '000000'])->assertStatus(403);
})->with([
    'verificar' => '/api/v1/auth/2fa/verify',
    'dar de alta' => '/api/v1/auth/2fa/enrol',
    'confirmar' => '/api/v1/auth/2fa/confirm',
])->group('RS-06', 'RQ-07');

it('deniega los tres endpoints de segundo factor sin token', function (string $uri): void {
    Api::guest()->post($uri, ['code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with([
    'verificar' => '/api/v1/auth/2fa/verify',
    'dar de alta' => '/api/v1/auth/2fa/enrol',
    'confirmar' => '/api/v1/auth/2fa/confirm',
])->group('RQ-07', 'RS-06');

it('rechaza un codigo con forma imposible', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($user);

    Api::as(ManagementUsers::pendingTokenFor($user))
        ->post('/api/v1/auth/2fa/verify', ['code' => 'abcdef'])
        ->assertStatus(422);
})->group('RF-ID-01');

it('no obliga a segundo factor a un rol que no lo tiene, y si a quien lo activo', function (): void {
    // RS-06 obliga a `admin`, `rrhh` y `auditor`. El responsable no entra: su
    // alcance esta acotado (RF-ID-03). Pero si el mismo lo activa, se le pide.
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => $responsable->email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(200);

    RateLimiter::clear('auth-ip:127.0.0.1');
    Auth::forgetGuards();

    ManagementUsers::withActiveSecondFactor($responsable);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => $responsable->email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(202)->assertJsonPath('enrolment_required', false);
})->group('RS-06', 'RF-ID-02');
