<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\RateLimiter;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * **Los dos techos de `/auth/2fa/*` y por que son dos** (RS-06, RS-12, RF-ID-01).
 *
 * Hay tres controles sobre estos tres endpoints y ninguno sustituye a los otros:
 *
 * | Control | Que cuenta | Eje | Donde vive |
 * |---|---|---|---|
 * | Nginx (§7.2) | peticiones | origen | el borde |
 * | zona `2fa` (§7.1) | peticiones | cuenta **y** origen | `IdentityServiceProvider` |
 * | `identity.two_factor.max_attempts` (§7.5) | **fallos de codigo** | cuenta | el caso de uso |
 *
 * Este fichero fija los dos ejes que se rompieron a la vez en la tarea 2.1 y que
 * hay que arreglar juntos, porque corregir el primero sin el segundo abre la
 * fuerza bruta:
 *
 * 1. **El techo por cuenta tiene que discriminar cuentas.** La zona `auth`
 *    componia su clave con `email`, que en estos tres endpoints no existe: la
 *    clave real era la constante `auth-account:` y los 5 r/m eran un cubo unico
 *    para toda la instalacion.
 * 2. **El bloqueo por fallos no puede depender del origen.** Con la IP en la
 *    clave, agotar los cinco intentos y salir por otra direccion estrenaba
 *    contador — y quien barre un espacio de 10^6 codigos tiene tantas direcciones
 *    como quiera.
 *
 * **Cada prueba aisla su eje bajando el techo del otro control**, que es la unica
 * forma de que un `429` signifique una sola cosa. Sin eso, las dos pruebas
 * pasarian en verde con cualquiera de los dos fallos vivo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    RateLimiter::clear('auth-ip:127.0.0.1');

    config()->set('identity.two_factor.required_roles', ['admin', 'rrhh', 'auditor']);
});

it('no deja que una cuenta agote el cupo de segundo factor de las demas', function (): void {
    // EL EJE DE LA CUENTA, aislado del de la IP —cada una usa la suya— y del
    // bloqueo por fallos, cuyo techo se sube para que no pueda ser el que
    // responda `429`.
    config()->set('identity.two_factor.rate_limit_per_minute', 5);
    config()->set('identity.two_factor.max_attempts', 500);

    $ruidosa = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($ruidosa);
    $retoRuidoso = ManagementUsers::pendingTokenFor($ruidosa);

    $legitima = ManagementUsers::withRole(UserRole::ADMIN);
    $secreto = ManagementUsers::withActiveSecondFactor($legitima);
    $retoLegitimo = ManagementUsers::pendingTokenFor($legitima);

    foreach (range(1, 5) as $ignored) {
        Api::as($retoRuidoso)->fromIp('1.1.1.1')
            ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
            ->assertStatus(401);
    }

    // Su cupo, agotado.
    Api::as($retoRuidoso)->fromIp('1.1.1.1')
        ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(429);

    // Y la de al lado entra. Con el cubo unico, esta era la peticion que recibia
    // `429` sin haber hecho nada: una cuenta cualquiera dejaba al hotel entero
    // sin poder completar su segundo factor.
    Api::as($retoLegitimo)->fromIp('2.2.2.2')
        ->post('/api/v1/auth/2fa/verify', ['code' => ManagementUsers::totpCodeFor($secreto)])
        ->assertValidResponse(200)
        ->assertJsonPath('user.uuid', $legitima->uuid);
})->group('RF-ID-01', 'RS-06', 'RS-12');

it('mantiene el bloqueo por codigos fallidos aunque cambie el origen', function (): void {
    // EL EJE DEL BLOQUEO, aislado del techo de peticiones: la zona se sube muy por
    // encima de los seis intentos para que el `429` del final solo pueda venir del
    // contador de fallos.
    config()->set('identity.two_factor.rate_limit_per_minute', 100);
    config()->set('identity.two_factor.max_attempts', 5);

    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secreto = ManagementUsers::withActiveSecondFactor($user);
    $reto = ManagementUsers::pendingTokenFor($user);

    foreach (range(1, 5) as $ignored) {
        Api::as($reto)->fromIp('1.1.1.1')
            ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
            ->assertStatus(401);
    }

    // Sexto intento, **otra direccion**. Con la IP en la clave del contador, esta
    // peticion estrenaba cupo y devolvia `401`: el bloqueo se rodeaba saltando de
    // red, que es exactamente lo que un atacante hace y un usuario legitimo no.
    Api::as($reto)->fromIp('2.2.2.2')
        ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // Y con el codigo bueno tampoco: el bloqueo es del sujeto, no de la peticion.
    Api::as($reto)->fromIp('3.3.3.3')
        ->post('/api/v1/auth/2fa/verify', ['code' => ManagementUsers::totpCodeFor($secreto)])
        ->assertStatus(429);
})->group('RF-ID-01', 'RS-06', 'RS-12');

it('niega el alta del segundo factor a quien esta bloqueado por intentos de codigo', function (): void {
    // El alta se alcanza con la contrasena y nada mas, asi que sin esta
    // comprobacion era la salida del bloqueo: agotados los intentos, un secreto
    // nuevo y a seguir probando.
    config()->set('identity.two_factor.rate_limit_per_minute', 100);
    config()->set('identity.two_factor.max_attempts', 2);

    // Sin segundo factor todavia: es la cuenta que puede darse de alta.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $reto = ManagementUsers::pendingTokenFor($user);

    foreach (range(1, 2) as $ignored) {
        Api::as($reto)->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
            ->assertStatus(401);
    }

    Api::as($reto)->post('/api/v1/auth/2fa/enrol')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RF-ID-01', 'RS-06', 'RS-12');

it('cierra el reto anterior al abrir uno nuevo', function (): void {
    // Un reto vale minutos, y en ese hueco cada acceso con la contrasena correcta
    // dejaba un token vivo mas. Quien tiene la contrasena de alguien —el escenario
    // contra el que existe RS-06— podia sembrar retos y esperar a que la persona
    // cantara un codigo, con varias oportunidades abiertas en lugar de una.
    config()->set('identity.two_factor.rate_limit_per_minute', 100);

    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secreto = ManagementUsers::withActiveSecondFactor($user);

    $primero = Api::guest()->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(202)->json('challenge_token');

    RateLimiter::clear('auth-ip:127.0.0.1');

    $segundo = Api::guest()->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => ManagementUsers::PASSWORD,
    ])->assertValidResponse(202)->json('challenge_token');

    expect($primero)->toBeString();
    expect($segundo)->toBeString();
    expect($segundo)->not->toBe($primero);

    Api::as(\is_string($primero) ? $primero : '')
        ->post('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');

    // Y el ultimo sigue sirviendo: lo que se cierra es el anterior, no la puerta.
    Api::as(\is_string($segundo) ? $segundo : '')
        ->post('/api/v1/auth/2fa/verify', ['code' => ManagementUsers::totpCodeFor($secreto)])
        ->assertValidResponse(200);
})->group('RF-ID-01', 'RS-06');
