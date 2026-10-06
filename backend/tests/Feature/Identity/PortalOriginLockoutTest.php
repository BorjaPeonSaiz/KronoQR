<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Shared\AuthenticationTrail;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El bloqueo por ORIGEN del acceso al portal (RS-12, ADR-050 §2), por HTTP.
 *
 * Lo que solo se ve aqui: que el `429` sale ANTES de mirar el codigo y el PIN
 * —tambien con el PIN correcto—, que no toca el contador de ningun empleado, que
 * un acierto no pone la cuenta a cero, que otro origen no se ve afectado, que
 * IPv6 cuenta por `/64` y que el rastro es un asiento por apertura, sin la IP en
 * el `payload` y con techo por hora. La regla, sin HTTP, esta en
 * `Unit/Identity/Domain/OriginLockoutPolicyTest`.
 *
 * El limitador de la zona `portal` se levanta: diez peticiones por minuto son
 * menos que los veinte fallos que hay que acumular, y su `429` taparia este.
 */

uses(RefreshDatabase::class);

const PORTAL_ORIGIN_LOCKOUT_IP = '203.0.113.7';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.portal.origin_lockout.max_failures', 20);
    config()->set('identity.portal.origin_lockout.window_seconds', 900);
    config()->set('identity.portal.origin_lockout.lockout_seconds', 3600);
    config()->set('identity.portal.origin_lockout.audit_ceiling_per_hour', 60);
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);

    app(Cache::class)->clear();

    FrozenTime::at('2026-10-06 09:00:00');
});

/**
 * @return array{uuid: string, code: string}
 */
function empleadoDelBloqueoPorOrigen(string $pin = PortalLogins::PIN): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del origen'));

    EmployeePins::issue($uuid, $pin);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

/**
 * Fallos desde un origen, cada uno con un codigo que no existe: son los que
 * prueba quien barre la plantilla, y no abren el bloqueo de ningun empleado.
 */
function fallosDesdeElOrigen(int $veces, string $ip = PORTAL_ORIGIN_LOCKOUT_IP): void
{
    for ($i = 0; $i < $veces; $i++) {
        Api::guest()->fromIp($ip)->post('/api/v1/me/login', [
            'employee_code' => 'NOEXISTE'.$i,
            'pin' => '000999',
        ])->assertStatus(401);
    }
}

it('cierra el portal al origen al vigesimo fallo, tambien con el PIN correcto', function (): void {
    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(20);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])
        ->assertValidResponse(429)
        ->assertHeader('Retry-After', '3600')
        ->assertJsonPath('type', 'urn:kronoqr:problem:portal-origin-locked');
})->group('RS-12', 'RF-ID-06', 'RF-ID-08');

it('no cierra nada con diecinueve fallos', function (): void {
    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(19);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertValidResponse(200);
})->group('RS-12', 'RF-ID-06');

it('no suma al contador del empleado mientras el origen esta bloqueado', function (): void {
    // Dos fallos contra la persona antes del bloqueo; uno mas la bloquearia
    // (3 fallos, RS-12). Durante el bloqueo por origen se insiste cinco veces
    // con su codigo: si alguna llegara al verificador, al terminar la hora
    // estaria bloqueada y no entraria.
    $empleado = empleadoDelBloqueoPorOrigen();

    foreach ([1, 2] as $_) {
        Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '000999',
        ])->assertStatus(401);
    }

    fallosDesdeElOrigen(18);

    for ($i = 0; $i < 5; $i++) {
        Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '000999',
        ])->assertStatus(429);
    }

    expect(app(PinAttempts::class)->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeFalse();

    // Pasada la hora, el origen vuelve a evaluarse y la persona entra. El
    // contador por empleado es un singleton con su reloj: se olvida para que
    // lo reconstruya con el nuevo, como pasa solo en cada peticion real.
    FrozenTime::at('2026-10-06 10:00:01');
    app()->forgetInstance(PinAttempts::class);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertValidResponse(200);
})->group('RS-12', 'RF-ID-06');

it('no deja que un acierto ponga la cuenta del origen a cero', function (): void {
    // Si un acierto la vaciara, quien conoce su propio PIN lo intercalaria entre
    // intentos contra los de otros (ADR-050).
    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(19);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);

    fallosDesdeElOrigen(1);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(429);
})->group('RS-12', 'RF-ID-06');

it('no afecta a otro origen', function (): void {
    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(20);

    Api::guest()->fromIp('198.51.100.20')->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertValidResponse(200);
})->group('RS-12', 'RF-ID-06');

it('cuenta una IPv6 por su /64 entero', function (): void {
    // Rotar los 64 bits bajos no es cambiar de origen: cualquier conexion
    // domestica recibe un /64 entero.
    $empleado = empleadoDelBloqueoPorOrigen();

    for ($i = 0; $i < 20; $i++) {
        fallosDesdeElOrigen(1, '2001:db8:1:2::'.dechex($i + 1));
    }

    Api::guest()->fromIp('2001:db8:1:2:ffff::9')->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(429);

    Api::guest()->fromIp('2001:db8:1:3::1')->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);
})->group('RS-12', 'RF-ID-06');

it('deja un asiento por apertura, sin la IP en el payload, y lo cuenta en la metrica', function (): void {
    $metricas = AuthenticationTrail::countingMetrics();
    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(20);

    for ($i = 0; $i < 3; $i++) {
        Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => PortalLogins::PIN,
        ])->assertStatus(429);
    }

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.origin_locked')
        ->and($asiento['actor_type'])->toBe('system')
        ->and($asiento['subject_type'])->toBeNull()
        // La columna `ip` en claro, como todo asiento `auth.*` (ADR-039)...
        ->and($asiento['ip'])->toBe(PORTAL_ORIGIN_LOCKOUT_IP)
        // ...y en el payload solo su seudonimo.
        ->and($asiento['payload']['channel'])->toBe('portal')
        ->and($asiento['payload']['failures'])->toBe(20)
        ->and($asiento['payload']['seconds'])->toBe(3600)
        ->and($asiento['payload']['ip_hash'])->toBeString()
        ->and($asiento['raw'])->not->toContain(PORTAL_ORIGIN_LOCKOUT_IP)
        ->and($asiento['raw'])->not->toContain($empleado['code']);

    expect($metricas->countOf(AuthChannel::PORTAL, AuthOutcome::ORIGIN_LOCKED))->toBe(1)
        // 20 rechazos genericos + 3 por origen bloqueado.
        ->and($metricas->countOf(AuthChannel::PORTAL, AuthOutcome::FAILURE))->toBe(23)
        ->and($metricas->countOf(AuthChannel::PORTAL, AuthOutcome::LOCKOUT))->toBe(0);
})->group('RS-12', 'RS-13', 'RF-ID-06');

it('no deja asiento por encima del techo por hora, pero bloquea igual y lo apunta en el log', function (): void {
    config()->set('identity.portal.origin_lockout.audit_ceiling_per_hour', 0);

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    $empleado = empleadoDelBloqueoPorOrigen();

    fallosDesdeElOrigen(20);

    Api::guest()->fromIp(PORTAL_ORIGIN_LOCKOUT_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(429);

    $bloqueos = array_values(array_filter(
        $apuntes,
        static fn (array $apunte): bool => $apunte['message'] === 'auth.origin_locked',
    ));

    expect(DB::table('audit_log')->where('action', 'auth.origin_locked')->count())->toBe(0)
        ->and($bloqueos)->toHaveCount(1)
        ->and($bloqueos[0]['context']['audited'])->toBeFalse()
        ->and($bloqueos[0]['context']['origin_hash'])->toBeString()
        ->and(json_encode($bloqueos[0]['context'], JSON_THROW_ON_ERROR))->not->toContain(PORTAL_ORIGIN_LOCKOUT_IP);
})->group('RS-12', 'RS-13');

it('no revela la longitud del PIN: siete cifras es un PIN incorrecto y nueve un error de forma', function (): void {
    // RS-03: la forma admitida es 6 a 8 sea cual sea el ajuste. Un 7 es el
    // mismo `401` que un PIN de 6 equivocado; solo lo que no puede ser un PIN
    // de ninguna longitud es `400`.
    $empleado = empleadoDelBloqueoPorOrigen();

    $seis = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '000999',
    ])->assertValidResponse(401);

    $siete = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '0009991',
    ])->assertValidRequest()->assertValidResponse(401);

    expect($siete->json())->toBe($seis->json());

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '000999123',
    ])->assertValidResponse(400);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '00099',
    ])->assertValidResponse(400);
})->group('RS-03', 'RF-ID-06', 'RF-ID-09');

it('acepta un PIN de ocho cifras y un PIN de seis anterior sigue valiendo', function (): void {
    // Transicion de ADR-050: la comprobacion compara el hash, no la longitud.
    $ocho = empleadoDelBloqueoPorOrigen('48392017');
    $seis = empleadoDelBloqueoPorOrigen();

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $ocho['code'],
        'pin' => '48392017',
    ])->assertValidRequest()->assertValidResponse(200);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $seis['code'],
        'pin' => PortalLogins::PIN,
    ])->assertValidResponse(200);

    // Los seis primeros de un PIN de ocho no son su PIN.
    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $ocho['code'],
        'pin' => '483920',
    ])->assertValidResponse(401);
})->group('RF-ID-09', 'RF-ID-06');
