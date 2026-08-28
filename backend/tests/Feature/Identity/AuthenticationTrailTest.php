<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Shared\AuthenticationTrail;

/*
 * OWASP A09 — **la autenticacion del panel deja rastro consultable**.
 *
 * Antes de esto, `AuditAction` no tenia ni un caso de autenticacion: se podian
 * probar contrasenas contra `/auth/login` toda la noche y, a la manana
 * siguiente, no habia ninguna consulta que respondiera si habia pasado algo.
 *
 * ## Lo que estas pruebas fijan
 *
 * ```
 * entra           audit_log   auth.login_succeeded   actor y sujeto = la cuenta
 * sale            audit_log   auth.logout
 * se bloquea      audit_log   auth.lockout_started   actor system, sin sujeto
 * falla           NO audit_log; log auth.login_failed + contador
 * ```
 *
 * El reparto y su porque —el candado global de ADR-010 por el que pasa cada
 * fichaje— estan en
 * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`. Aqui se
 * afirma con un numero —cero filas— para que nadie lo «mejore» anadiendo el
 * asiento que falta.
 *
 * **Y que nada de esto lleve el correo tampoco es cosmetica** (regla dura 21):
 * `audit_log` se conserva cuatro anos y se enseña en una inspeccion; un asiento
 * de acceso con el correo dentro convierte esa tabla en un directorio de
 * cuentas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El limitador de la ruta (5 r/m) cuenta por origen y su contador vive en la
    // cache: se limpia entre pruebas para que una no herede el cupo de otra.
    RateLimiter::clear('auth-ip:127.0.0.1');
});

it('deja asiento del acceso correcto al panel, con la cuenta y sin su correo', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $user = ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    expect(DB::table('audit_log')->count())->toBe(0);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertStatus(200);

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.login_succeeded')
        // El actor es la cuenta y no `system`: durante el acceso todavia no hay
        // sesion, asi que si nadie lo resolviera el asiento diria que quien
        // inicio sesion fue el sistema.
        ->and($asiento['actor_type'])->toBe('user')
        ->and($asiento['actor_id'])->toBe($user->id)
        ->and($asiento['subject_type'])->toBe('user')
        ->and($asiento['subject_id'])->toBe($user->id)
        ->and($asiento['payload']['channel'])->toBe('management')
        ->and($asiento['payload']['user_uuid'])->toBe($user->uuid)
        // Regla dura 21: ni el correo, ni el nombre, ni la contrasena.
        ->and($asiento['raw'])->not->toContain('rrhh@hotel.example')
        ->and($asiento['raw'])->not->toContain('Cuenta de prueba')
        ->and($asiento['raw'])->not->toContain(ManagementUsers::PASSWORD);

    expect($contador->countOf(AuthChannel::MANAGEMENT, AuthOutcome::SUCCESS))->toBe(1);
})->group('RS-12', 'RS-05', 'RF-ID-01');

it('guarda el origen en la columna ip, como los otros cinco escritores', function (): void {
    // ADR-039. `audit_log` tiene su columna `ip` y **un solo criterio para toda
    // la tabla**: un seudonimo en el payload de tres acciones y la direccion en
    // claro en las otras veinte obligaria a quien investiga a saber de que accion
    // viene cada fila para saber que significa su origen. Esa tabla no sale de la
    // instalacion.
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertStatus(200);

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['ip'])->toBe('127.0.0.1')
        // Y una sola representacion: el seudonimo no se duplica en el payload.
        ->and($asiento['payload'])->not->toHaveKey('ip_hash');
})->group('RS-12', 'RS-05');

it('seudonimiza el origen en el log tecnico, que es el que viaja al fabricante', function (): void {
    // El log tecnico si sale de la instalacion, dentro del paquete de
    // diagnostico anonimizado (ADR-020): ahi la IP no puede ir en claro. El
    // `ip_hash` es ademas la clave que une «400 intentos» con «y despues uno
    // entro» — `docs/runbooks/ataque-a-credenciales.md` §4.3.
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => 'no-es-la-contrasena-buena-1!',
    ])->assertStatus(401);

    expect($apuntes)->toHaveCount(1)
        ->and($apuntes[0]['context']['ip_hash'])->toBeString()
        ->and($apuntes[0]['context']['ip_hash'])->not->toBe('127.0.0.1')
        ->and(json_encode($apuntes[0]['context'], JSON_THROW_ON_ERROR))
        ->not->toContain('127.0.0.1');
})->group('RS-12', 'RS-05');

it('deja asiento del cierre de sesion', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);

    Api::as($token)->post('/api/v1/auth/logout')->assertStatus(204);

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.logout')
        ->and($asiento['actor_type'])->toBe('user')
        ->and($asiento['actor_id'])->toBe($user->id)
        ->and($asiento['payload']['channel'])->toBe('management')
        ->and($asiento['payload']['user_uuid'])->toBe($user->uuid);
})->group('RS-12', 'RS-05', 'RF-ID-01');

it('no escribe en audit_log cuando el acceso falla, y aun asi lo deja consultable', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => 'no-es-la-contrasena-buena-1!',
    ])->assertStatus(401);

    // La cadena de hash no se toca: un ataque de fuerza bruta no puede meter
    // escrituras en el camino por el que pasa cada fichaje.
    expect(DB::table('audit_log')->count())->toBe(0);

    expect($apuntes)->toHaveCount(1)
        ->and($apuntes[0]['message'])->toBe('auth.login_failed')
        ->and($apuntes[0]['context']['channel'])->toBe('management')
        ->and($apuntes[0]['context']['reason'])->toBe('invalid_credentials')
        ->and($apuntes[0]['context']['ip_hash'])->toBeString()
        // RS-03: el servidor tampoco sabe si la cuenta existe en el camino que
        // produce la respuesta, asi que no hay a quien nombrar.
        ->and($apuntes[0]['context']['subject_uuid'])->toBeNull()
        ->and(json_encode($apuntes[0]['context'], JSON_THROW_ON_ERROR))
        ->not->toContain('rrhh@hotel.example');

    expect($contador->countOf(AuthChannel::MANAGEMENT, AuthOutcome::FAILURE))->toBe(1);
})->group('RS-12', 'RS-03', 'RF-ID-01');

it('registra igual el fallo de una cuenta que existe y el de una que no', function (): void {
    // RS-03 y regla dura 17. Si el log distinguiera los dos casos, el oraculo
    // que la respuesta no da lo daria el servidor por dentro — y ese log viaja
    // al fabricante en el paquete de diagnostico.
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => 'no-es-la-contrasena-buena-1!',
    ])->assertStatus(401);

    RateLimiter::clear('auth-ip:127.0.0.1');

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'nadie@hotel.example',
        'password' => 'no-es-la-contrasena-buena-1!',
    ])->assertStatus(401);

    expect($apuntes)->toHaveCount(2)
        ->and($apuntes[0]['context'])->toBe($apuntes[1]['context']);
})->group('RS-03', 'RS-12');

it('deja asiento del bloqueo del panel, una sola vez y sin nombrar la cuenta', function (): void {
    $contador = AuthenticationTrail::countingMetrics();

    config()->set('identity.login.max_attempts', 2);
    ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    for ($intento = 0; $intento < 2; $intento++) {
        RateLimiter::clear('auth-ip:127.0.0.1');

        Api::guest()->post('/api/v1/auth/login', [
            'email' => 'rrhh@hotel.example',
            'password' => 'contrasena-equivocada-1!',
        ])->assertStatus(401);
    }

    RateLimiter::clear('auth-ip:127.0.0.1');

    // Un intento mas, ya bloqueado: no puede abrir un segundo asiento.
    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertStatus(429);

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.lockout_started')
        // El bloqueo lo decide el servidor, asi que su actor es el sistema de
        // verdad: no hace falta inventar un tipo de actor que la tabla no tiene.
        ->and($asiento['actor_type'])->toBe('system')
        ->and($asiento['payload']['channel'])->toBe('management')
        ->and($asiento['payload']['lock_seconds'])->toBeGreaterThan(0)
        // El contador del panel es por «correo mas origen»: el servidor no sabe
        // —ni debe averiguar— si ese correo es de una cuenta real.
        ->and($asiento['payload'])->not->toHaveKey('user_uuid')
        ->and($asiento['raw'])->not->toContain('rrhh@hotel.example');

    // `lockout` cuenta bloqueos ABIERTOS —uno— y no intentos rechazados: es lo
    // que permite que `KronoqrAuthLockouts` lea «tres o mas» como «tres cuentas
    // distintas», que es la firma del credential stuffing. Los tres intentos
    // —dos con la contrasena mala y el tercero contra el bloqueo— son `failure`.
    expect($contador->countOf(AuthChannel::MANAGEMENT, AuthOutcome::LOCKOUT))->toBe(1)
        ->and($contador->countOf(AuthChannel::MANAGEMENT, AuthOutcome::FAILURE))->toBe(3);
})->group('RS-12', 'RS-02', 'RF-ID-01');

it('mantiene la cadena de hash verificable con los asientos de autenticacion dentro', function (): void {
    // El modo de fallo silencioso de ADR-010: un payload que no se serializa de
    // forma canonica rompe la verificacion diaria sin que nadie haya tocado
    // nada, y una alerta critica que suena sola se acaba silenciando.
    $user = ManagementUsers::withRole(UserRole::RRHH, 'rrhh@hotel.example');

    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'rrhh@hotel.example',
        'password' => ManagementUsers::PASSWORD,
    ])->assertStatus(200);

    Api::as(ManagementUsers::tokenFor($user))
        ->post('/api/v1/auth/logout')
        ->assertStatus(204);

    expect(DB::table('audit_log')->count())->toBe(2)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RS-07', 'RS-12');
