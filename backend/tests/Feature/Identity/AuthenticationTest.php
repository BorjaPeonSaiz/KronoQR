<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * Acceso al panel de gestion (RF-ID-01, RF-ID-02).
 *
 * Feature **y contrato a la vez**: cada respuesta se valida contra
 * `docs/api/openapi.yaml` con Spectator. Una prueba de feature que solo mira
 * campos sueltos deja pasar el caso que rompe a los tres frontends —un campo de
 * mas, un tipo cambiado—, porque el cliente TypeScript se genera del contrato.
 *
 * **El segundo factor tiene su propio fichero** (`TwoFactorAuthenticationTest`).
 * Aqui se prueba el acceso con contrasena de una cuenta que **no** esta obligada
 * a llevarlo, y el unico caso de 2FA que pertenece a este flujo: que un rol
 * obligado reciba `202` en lugar de sesion.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // RS-06: el valor de serie obliga a `admin`, `rrhh` y `auditor`. Casi todo
    // este fichero prueba el acceso **con contrasena y sin reto**, asi que se
    // vacia la lista para no tener que resolver un TOTP en cada caso. Que la
    // lista sea configuracion y no una constante es justo lo que lo permite
    // (regla dura 13); el reto tiene sus propias pruebas mas abajo y en
    // `TwoFactorAuthenticationTest`.
    config()->set('identity.two_factor.required_roles', []);

    // El limitador de la ruta (5 r/m) cuenta por origen y su contador vive en la
    // cache: se limpia entre pruebas para que una no herede el cupo gastado por
    // otra. El bloqueo por intentos fallidos es otro control y tiene su propia
    // prueba.
    RateLimiter::clear('auth-ip:127.0.0.1');
});

it('deja entrar a una persona de RRHH con su correo y su contrasena', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    $response = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
        'device_name' => 'Panel de gestion',
    ]);

    $response->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.uuid', $user->uuid)
        ->assertJsonPath('user.roles', ['rrhh']);

    // Los ambitos del token son los permisos del rol (§7.3), no una lista aparte.
    expect($response->json('user.abilities'))->toContain('employees:*');
    expect($response->json('token'))->toBeString();
})->group('RF-ID-01', 'RF-ID-02');

it('acepta el correo escrito con otras mayusculas', function (): void {
    // La columna es `citext`: `Rrhh@hotel.example` es la misma cuenta.
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'RRHH@Hotel.Example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(200);
})->group('RF-ID-01');

it('anota el ultimo acceso', function (): void {
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    expect($user->last_login_at)->toBeNull();

    Api::guest()->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(200);

    expect(DB::table('users')->where('uuid', $user->uuid)->value('last_login_at'))->not->toBeNull();
})->group('RF-ID-01');

it('responde lo mismo ante contrasena incorrecta, correo desconocido y cuenta desactivada', function (): void {
    // Distinguirlos convertiria el acceso al panel en un comprobador de cuentas
    // de la empresa. Se comprueba que las tres respuestas son identicas campo a
    // campo, no solo que las tres son 401.
    $user = ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    $wrongPassword = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => 'no-es-la-contrasena-buena-1!',
    ]);

    RateLimiter::clear('auth-ip:127.0.0.1');

    $unknownEmail = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'nadie@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ]);

    RateLimiter::clear('auth-ip:127.0.0.1');

    DB::table('users')->where('uuid', $user->uuid)->update(['is_active' => false]);

    $deactivated = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ]);

    $wrongPassword->assertValidResponse(401);
    $unknownEmail->assertValidResponse(401);
    $deactivated->assertValidResponse(401);

    expect($wrongPassword->json())->toBe($unknownEmail->json())
        ->and($wrongPassword->json())->toBe($deactivated->json())
        ->and($wrongPassword->json('type'))->toBe('urn:kronoqr:problem:invalid-credentials');
})->group('RF-ID-01', 'RS-03');

it('bloquea la cuenta tras los intentos fallidos configurados', function (): void {
    // RF-ID-01. El bloqueo cuenta FALLOS por cuenta, que es lo que frena a quien
    // prueba contrasenas contra un correo conocido; el `throttle` de la ruta
    // cuenta peticiones por origen y es otro control.
    config()->set('identity.login.max_attempts', 2);

    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    for ($attempt = 0; $attempt < 2; $attempt++) {
        RateLimiter::clear('auth-ip:127.0.0.1');

        Api::guest()->post('/api/v1/auth/login', [
            'email' => 'rrhh@hotel.example',
            'password' => 'contrasena-equivocada-1!',
        ])->assertStatus(401);
    }

    RateLimiter::clear('auth-ip:127.0.0.1');

    // Y ahora con la contrasena CORRECTA: sigue bloqueado. Si el bloqueo cediera
    // ante el acierto, seria un oraculo que confirma cuando se acierta.
    $blocked = Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ]);

    $blocked->assertValidResponse(429)->assertHeader('Retry-After');

    expect($blocked->json('type'))->toBe('urn:kronoqr:problem:too-many-requests');
})->group('RF-ID-01', 'RS-02');

it('limita el acceso a cinco peticiones por minuto', function (): void {
    // Zona de autenticacion del §7.1. Nginx aplica ademas la suya en el borde;
    // esta es la de la aplicacion, que si distingue cuentas.
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        Api::guest()->post('/api/v1/auth/login', [
            'email' => 'otra-'.$attempt.'@hotel.example',
            'password' => 'lo-que-sea-1!',
        ]);
    }

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertStatus(429);
})->group('RS-02');

it('rechaza una peticion de acceso mal formada', function (): void {
    Api::guest()->post('/api/v1/auth/login', ['email' => 'esto-no-es-un-correo'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-ID-01');

it('devuelve el usuario, su rol y su ambito de la sesion en curso', function (): void {
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($user))
        ->get('/api/v1/auth/me')
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $user->uuid)
        ->assertJsonPath('roles', ['admin']);
})->group('RF-ID-01', 'RF-ID-02');

it('no deja consultar la sesion sin token', function (): void {
    Api::guest()->get('/api/v1/auth/me')
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->group('RF-ID-01');

it('deja de valer el token de una cuenta desactivada sin esperar a que caduque', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);

    Api::as($token)->get('/api/v1/auth/me')->assertStatus(200);

    DB::table('users')->where('uuid', $user->uuid)->update(['is_active' => false]);
    Auth::forgetGuards();

    Api::as($token)->get('/api/v1/auth/me')->assertValidResponse(401);
})->group('RF-ID-01');

it('cierra la sesion revocando solo el token con el que se llama', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $laptop = ManagementUsers::tokenFor($user);
    $tablet = ManagementUsers::tokenFor($user);

    Api::as($laptop)->post('/api/v1/auth/logout')->assertValidResponse(204);

    // Artefacto de la suite, no del producto: dentro de una prueba las llamadas
    // comparten aplicacion y el guard de Sanctum cachea el usuario que ya
    // resolvio. En produccion cada peticion arranca su propia aplicacion.
    Auth::forgetGuards();

    // El portatil queda fuera...
    Api::as($laptop)->get('/api/v1/auth/me')->assertStatus(401);

    Auth::forgetGuards();

    // ...y la tablet donde estaba revisando incidencias, dentro.
    Api::as($tablet)->get('/api/v1/auth/me')->assertStatus(200);
})->group('RF-ID-01');

it('devuelve el alcance de la cuenta junto al rol y al ambito', function (): void {
    // RF-ID-03: `GET /auth/me` es lo que el panel usa para no ofrecer lo que
    // despues seria un 403. RRHH alcanza la plantilla entera.
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($user))->get('/api/v1/auth/me')
        ->assertValidResponse(200)
        ->assertJsonPath('scope.kind', 'all')
        ->assertJsonPath('scope.department_ids', []);
})->group('RF-ID-03', 'RF-ID-02');

it('detiene el acceso de un rol obligado a segundo factor', function (): void {
    // RS-06 en el flujo del acceso: la contrasena es correcta y aun asi no hay
    // sesion. El `202` lleva un `challenge_token`, nunca un `token`: un cliente
    // que los confundiera guardaria como sesion algo que no autoriza nada.
    config()->set('identity.two_factor.required_roles', ['rrhh']);

    $user = ManagementUsers::withRole(UserRole::RRHH);

    $response = Api::guest()->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => ManagementUsers::PASSWORD,
    ]);

    $response->assertValidRequest()
        ->assertValidResponse(202)
        ->assertJsonPath('token_type', 'Bearer')
        // Sin TOTP configurado todavia: hay que darlo de alta antes de entrar.
        ->assertJsonPath('enrolment_required', true)
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('user');

    expect($response->json('challenge_token'))->toBeString();

    // Y no ha entrado nadie: `last_login_at` sigue vacio (ADR-039). Un asiento de
    // acceso aqui diria en `audit_log` que alguien entro cuando lo que hizo fue
    // quedarse a medias.
    expect(DB::table('users')->where('uuid', $user->uuid)->value('last_login_at'))->toBeNull();
})->group('RF-ID-01', 'RS-06');

it('no deja que una sesion pendiente consulte nada, ni siquiera quien es', function (): void {
    // El token del `202` lleva un unico ambito, `2fa:pending`. `GET /auth/me` es
    // el unico endpoint que no puede exigir un ambito concreto —lo llaman los
    // cuatro roles con ambitos distintos— y por eso lleva `session.complete`.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $pending = ManagementUsers::pendingTokenFor($user);

    Api::as($pending)->get('/api/v1/auth/me')->assertStatus(401);

    Api::as($pending)->get('/api/v1/employees')->assertStatus(403);
})->group('RS-06', 'RF-ID-01');
