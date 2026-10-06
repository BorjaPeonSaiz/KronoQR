<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\AuthenticatePortalEmployeeCommand;
use App\Modules\Identity\Application\Exception\PortalAccessDenied;
use App\Modules\Identity\Application\Exception\PortalOriginLocked;
use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Application\UseCase\AuthenticatePortalEmployeeHandler;
use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use App\Modules\Identity\Infrastructure\Adapter\CachePortalOriginAttempts;
use App\Modules\Shared\Application\Port\PinAttempts;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Health\RedisOutage;
use Tests\Support\Time\FrozenTime;

/*
 * La cuenta de fallos por origen del portal sobre la cache `resilient` de
 * verdad (RS-12, ADR-050 §2).
 *
 * `Unit/Identity/Domain/OriginLockoutPolicyTest` prueba la regla y
 * `Feature/Identity/PortalOriginLockoutTest` el borde HTTP; esto prueba el
 * ALMACEN: que con Redis caido la cuenta sigue en el disco (si cayera a una
 * cache de memoria, cada peticion empezaria de cero y el bloqueo no llegaria
 * nunca) y que las caducidades son las que promete el ADR, con los dos relojes
 * detenidos.
 *
 * Por el caso de uso y no por HTTP: con Redis caido el limitador de la zona
 * `portal` falla cerrado (`config/cache.php`) y su `500` taparia lo que se mide.
 */

uses(RefreshDatabase::class);

const CACHE_PORTAL_ORIGIN_IP = '203.0.113.40';

const CACHE_PORTAL_ORIGIN_KEY = 'identity:portal-origin:203.0.113.40';

beforeEach(function (): void {
    config()->set('identity.portal.origin_lockout.max_failures', 20);
    config()->set('identity.portal.origin_lockout.window_seconds', 900);
    config()->set('identity.portal.origin_lockout.lockout_seconds', 3600);
    config()->set('identity.portal.origin_lockout.audit_ceiling_per_hour', 60);

    FrozenTime::at('2026-10-06 09:00:00');
});

afterEach(function (): void {
    RedisOutage::end();
});

/**
 * Tira Redis y olvida el almacen ya construido: el singleton guarda la cache
 * que tenia al resolverse, y sin esto la prueba hablaria con la de antes.
 */
function cachePortalOriginSinRedis(): PortalOriginAttempts
{
    RedisOutage::begin();
    app()->forgetInstance(PortalOriginAttempts::class);

    return app(PortalOriginAttempts::class);
}

/**
 * Un intento de acceso al portal desde una direccion, resuelto en el momento
 * actual del reloj detenido, y su desenlace en una palabra.
 */
function cachePortalOriginIntento(string $codigo, string $ip = CACHE_PORTAL_ORIGIN_IP): string
{
    // El caso de uso y el contador por empleado guardan el reloj con el que se
    // construyeron: se rehacen para que lean el instante actual.
    app()->forgetInstance(PinAttempts::class);

    try {
        app(AuthenticatePortalEmployeeHandler::class)
            ->handle(new AuthenticatePortalEmployeeCommand($codigo, '000999', $ip));
    } catch (PortalOriginLocked $bloqueo) {
        return 'bloqueado '.$bloqueo->retryAfterSeconds;
    } catch (PortalAccessDenied) {
        return 'rechazado';
    }

    return 'sesion';
}

/**
 * @return list<string>
 */
function cachePortalOriginFallos(int $veces): array
{
    return array_map(
        static fn (int $i): string => cachePortalOriginIntento('NOEXISTE'.$i),
        range(1, $veces),
    );
}

it('sigue contando en el disco con Redis caido y cierra el portal al vigesimo fallo', function (): void {
    cachePortalOriginSinRedis();

    $desenlaces = cachePortalOriginFallos(20);

    expect($desenlaces)->toBe(array_fill(0, 20, 'rechazado'))
        ->and(cachePortalOriginIntento('NOEXISTE21'))->toBe('bloqueado 3600')
        ->and(app('cache')->store('file')->get(CACHE_PORTAL_ORIGIN_KEY))
        ->toBe(['failures' => [], 'locked_until' => 1_791_280_800]);
})->group('RS-12', 'RF-ID-08');

it('mantiene el bloqueo hasta el segundo 3599 y lo levanta en el 3600', function (): void {
    cachePortalOriginSinRedis();
    cachePortalOriginFallos(20);

    FrozenTime::at('2026-10-06 09:59:59');
    $unSegundoAntes = cachePortalOriginIntento('NOEXISTE21');

    FrozenTime::at('2026-10-06 10:00:00');
    $alCumplirseLaHora = cachePortalOriginIntento('NOEXISTE22');

    expect($unSegundoAntes)->toBe('bloqueado 1')
        ->and($alCumplirseLaHora)->toBe('rechazado');
})->group('RS-12', 'RF-ID-08');

it('empieza de cero cuando termina el bloqueo', function (): void {
    // Los veinte fallos que lo abrieron ya han pagado su hora: tras ella,
    // diecinueve mas no lo vuelven a cerrar.
    cachePortalOriginSinRedis();
    cachePortalOriginFallos(20);

    FrozenTime::at('2026-10-06 10:00:00');
    cachePortalOriginFallos(19);

    expect(cachePortalOriginIntento('NOEXISTE40'))->toBe('rechazado');
})->group('RS-12');

it('olvida la entrada al cumplirse su retencion en el disco', function (): void {
    $almacen = cachePortalOriginSinRedis();
    $origen = RequestOrigin::of(CACHE_PORTAL_ORIGIN_IP);

    $almacen->save($origen, new OriginAttemptHistory([1_791_277_200], null), 3600);

    FrozenTime::at('2026-10-06 09:59:59');
    $unSegundoAntes = $almacen->historyFor($origen)->failures;

    FrozenTime::at('2026-10-06 10:00:00');
    $alCumplirse = $almacen->historyFor($origen)->failures;

    expect($unSegundoAntes)->toBe([1_791_277_200])
        ->and($alCumplirse)->toBe([]);
})->group('RS-12');

it('cuenta las aperturas de cada hora en el disco con Redis caido', function (): void {
    $almacen = cachePortalOriginSinRedis();

    $deLasNueve = [
        $almacen->countLockOpening(1_791_277_200),
        $almacen->countLockOpening(1_791_277_200),
        $almacen->countLockOpening(1_791_277_200),
    ];

    expect($deLasNueve)->toBe([1, 2, 3])
        ->and($almacen->countLockOpening(1_791_280_800))->toBe(1);
})->group('RS-12', 'RS-13');

it('guarda la entrada en Redis con la retencion como caducidad', function (): void {
    // Redis no lee el reloj de la prueba: aqui se comprueba el TTL que recibe,
    // que es lo que decide cuando olvida la cuenta en una instalacion real.
    config()->set('cache.stores.resilient.stores', ['redis', 'file']);
    config()->set('cache.prefix', 'kronoqr-test-origin-');
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(PortalOriginAttempts::class);

    $almacen = app(PortalOriginAttempts::class);
    $origen = RequestOrigin::of(CACHE_PORTAL_ORIGIN_IP);

    $almacen->save($origen, new OriginAttemptHistory([], 1_791_280_800), 3600);

    $ttl = Redis::connection(config()->string('cache.stores.redis.connection'))->ttl('kronoqr-test-origin-'.CACHE_PORTAL_ORIGIN_KEY);

    $almacen->forget($origen);

    expect($ttl)->toBeGreaterThanOrEqual(3599)->toBeLessThanOrEqual(3600);
})->group('RS-12');

it('cuenta el fallo sin candado si otro proceso no lo suelta', function (): void {
    // Regla dura 19 y la misma promesa que el contador del PIN: el candado
    // ordena la cuenta, no la condiciona. Con el del origen retenido —un proceso
    // muerto con el cogido— se espera un numero fijo de intentos y el fallo se
    // cuenta igual; ni excepcion, ni fallo perdido.
    Sleep::fake();

    $almacen = new ArrayStore;
    $almacen->lock('identity:portal-origin-lock:'.CACHE_PORTAL_ORIGIN_KEY, 10)->get();

    $origenes = new CachePortalOriginAttempts(new Repository($almacen));
    $origen = RequestOrigin::of(CACHE_PORTAL_ORIGIN_IP);

    [$antes, $despues] = $origenes->update(
        $origen,
        static fn (OriginAttemptHistory $estado): OriginAttemptHistory => new OriginAttemptHistory([...$estado->failures, 1_791_277_200], null),
        900,
    );

    expect($antes->failures)->toBe([])
        ->and($despues->failures)->toBe([1_791_277_200])
        ->and($origenes->historyFor($origen)->failures)->toBe([1_791_277_200]);

    Sleep::assertSleptTimes(300);
})->group('RS-12', 'RF-ID-08');
