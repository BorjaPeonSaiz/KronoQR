<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Shared\AuthenticationTrail;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `identity:origin-unlock <ip>` (ADR-050 §2, dictamen de seguridad M3): levanta
 * el bloqueo por origen del portal y deja `auth.origin_unlocked` con el
 * `ip_hash`, nunca la IP en claro, y con actor `system` como los demas
 * comandos `identity:*`.
 */

uses(RefreshDatabase::class);

const UNLOCK_ORIGIN_COMMAND_IP = '203.0.113.9';

beforeEach(function (): void {
    app(Cache::class)->clear();
    FrozenTime::at('2026-10-06 09:00:00');
});

function origenBloqueadoParaElComando(string $ip = UNLOCK_ORIGIN_COMMAND_IP): RequestOrigin
{
    $origen = RequestOrigin::of($ip);

    app(PortalOriginAttempts::class)->update(
        $origen,
        static fn (): OriginAttemptHistory => new OriginAttemptHistory([], (int) strtotime('2026-10-06 10:00:00 UTC')),
        3600,
    );

    return $origen;
}

it('levanta el bloqueo y el portal vuelve a dejar entrar desde ese origen', function (): void {
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    EmployeePins::issue($uuid, PortalLogins::PIN);
    origenBloqueadoParaElComando();

    $login = static fn () => Api::guest()->fromIp(UNLOCK_ORIGIN_COMMAND_IP)->post('/api/v1/me/login', [
        'employee_code' => EmployeePins::codeOf($uuid),
        'pin' => PortalLogins::PIN,
    ]);

    $login()->assertStatus(429);

    expect(Artisan::call('identity:origin-unlock', ['ip' => UNLOCK_ORIGIN_COMMAND_IP]))->toBe(0);

    $login()->assertStatus(200);
})->group('RS-12', 'RF-ID-06');

it('deja asiento auth.origin_unlocked con el ip_hash, sin la IP y con actor system', function (): void {
    origenBloqueadoParaElComando();

    expect(Artisan::call('identity:origin-unlock', ['ip' => UNLOCK_ORIGIN_COMMAND_IP]))->toBe(0);

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.origin_unlocked')
        ->and($asiento['actor_type'])->toBe('system')
        ->and($asiento['subject_type'])->toBeNull()
        // La columna `ip` es la de quien ejecuta (en consola, ninguna real), nunca la desbloqueada.
        ->and($asiento['ip'])->not->toBe(UNLOCK_ORIGIN_COMMAND_IP)
        ->and($asiento['payload']['channel'])->toBe('portal')
        ->and($asiento['payload']['had_state'])->toBeTrue()
        ->and($asiento['payload']['ip_hash'])->toBeString()
        ->and($asiento['raw'])->not->toContain(UNLOCK_ORIGIN_COMMAND_IP);
})->group('RS-12', 'RS-13', 'RL-04');

it('levanta el /64 entero si se le da una IPv6', function (): void {
    $origen = origenBloqueadoParaElComando('2001:db8:1:2::1');

    expect(Artisan::call('identity:origin-unlock', ['ip' => '2001:db8:1:2:ffff::9']))->toBe(0);

    expect(app(PortalOriginAttempts::class)->historyFor($origen)->lockedUntil)->toBeNull();
})->group('RS-12');

it('deja constancia aunque no hubiera nada que levantar', function (): void {
    expect(Artisan::call('identity:origin-unlock', ['ip' => '198.51.100.1']))->toBe(0);

    expect(AuthenticationTrail::onlyAuditEntry()['payload']['had_state'])->toBeFalse();
})->group('RS-12', 'RS-13');

it('rechaza lo que no es una direccion sin escribir nada', function (): void {
    expect(Artisan::call('identity:origin-unlock', ['ip' => 'no-es-una-ip']))->not->toBe(0);

    expect(AuthenticationTrail::auditEntries())->toBe([]);
})->group('RS-12');

it('firma el desbloqueo con el mismo ip_hash que el bloqueo que levanta', function (): void {
    // Sin la IP en ninguno de los dos, el ip_hash es lo unico que permite a
    // quien audita emparejar el cierre con su apertura.
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.portal.origin_lockout.max_failures', 20);

    foreach (range(1, 20) as $i) {
        Api::guest()->fromIp(UNLOCK_ORIGIN_COMMAND_IP)->post('/api/v1/me/login', [
            'employee_code' => 'NOEXISTE'.$i,
            'pin' => '000999',
        ])->assertStatus(401);
    }

    Artisan::call('identity:origin-unlock', ['ip' => UNLOCK_ORIGIN_COMMAND_IP]);

    $porAccion = array_column(AuthenticationTrail::auditEntries(), 'payload', 'action');

    expect($porAccion['auth.origin_unlocked']['ip_hash'])->toBeString()
        ->toBe($porAccion['auth.origin_locked']['ip_hash']);
})->group('RS-12', 'RS-13', 'RL-04');
