<?php

declare(strict_types=1);

use App\Support\Health\DependencyProbe;
use App\Support\Health\EndpointUnreachable;
use App\Support\Network\BoundedReachability;
use App\Support\Redis\RedisCircuitBreaker;
use App\Support\Redis\RedisCircuitOpen;
use App\Support\Resilience\CircuitState;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Http\Api;
use Tests\Support\Time\WallClockBudget;

/*
 * `/ready` con un tope de tiempo por dependencia QUE INCLUYE EL DNS (R3-CH-02).
 *
 * La reverificacion de la 2.2.0 midio 15,7 s de `/ready` con PostgreSQL
 * parado: ni `PDO::ATTR_TIMEOUT` ni el tiempo de conexion de `phpredis` cubren
 * la resolucion del nombre, y con el contenedor desaparecido cada consulta al
 * DNS de Docker tardaba ~3,9 s. `ReadinessProbeTest` ya cubre una IP que no
 * enruta; aqui el caso que faltaba: **un nombre que no resuelve**.
 *
 * `.invalid` esta reservado (RFC 2606) y no resuelve nunca, en ninguna red: la
 * prueba no depende del entorno de quien la lanza. El techo de 3 s es el del
 * hallazgo: el tope es de 2 s por dependencia.
 *
 * Cada caso va dos veces: la afirmacion funcional —que falla, y con que— en la
 * suite de feature, y el techo de tiempo en la suite nocturna de rendimiento,
 * con la mediana de varias pasadas (D4 de la 2.3.0, {@see WallClockBudget}).
 */

const READINESS_BUDGET_HOST_QUE_NO_EXISTE = 'kronoqr-dependencia-desaparecida.invalid';

const READINESS_BUDGET_TECHO_SEGUNDOS = 3.0;

/** La conexion por defecto apunta a un PostgreSQL cuyo nombre ya no resuelve. */
function readinessBudgetPostgresDesaparecido(): void
{
    config()->set('database.connections.pgsql_desaparecida', [
        ...config()->array('database.connections.pgsql'),
        'host' => READINESS_BUDGET_HOST_QUE_NO_EXISTE,
    ]);
    config()->set('database.default', 'pgsql_desaparecida');
}

/** Redis apunta a un nombre que ya no resuelve, con el cliente sin conexion previa. */
function readinessBudgetRedisDesaparecido(): void
{
    config()->set('database.redis.default.host', READINESS_BUDGET_HOST_QUE_NO_EXISTE);
    app()->forgetInstance('redis');
    app()->forgetInstance(Redis::class);
}

/**
 * Un cortacircuitos de Redis de verdad, con su fichero propio, instalado en el
 * contenedor; se le pasa a `$body` y su fichero se borra al terminar.
 *
 * @template T
 *
 * @param  callable(RedisCircuitBreaker): T  $body
 * @return T
 */
function readinessBudgetConCortacircuitosDeRedis(callable $body): mixed
{
    $fichero = sys_get_temp_dir().'/kronoqr-redis-circuit-readiness-'.getmypid();
    $breaker = new RedisCircuitBreaker($fichero, 10.0, new MockClock, resolve(LoggerInterface::class));
    app()->instance(RedisCircuitBreaker::class, $breaker);

    try {
        return $body($breaker);
    } finally {
        @unlink($fichero);
    }
}

it('no da por alcanzable un nombre que no resuelve', function (): void {
    expect((new BoundedReachability)->reachable(READINESS_BUDGET_HOST_QUE_NO_EXISTE, 5432, DependencyProbe::BUDGET_SECONDS))->toBeFalse();
})->group('RNF-D-03', 'RF-PD-13');

it('dice que un nombre que no resuelve no es alcanzable antes de agotar el tope, en mediana', function (): void {
    $muestras = WallClockBudget::samples(static fn (): float => WallClockBudget::seconds(
        static fn (): bool => (new BoundedReachability)->reachable(READINESS_BUDGET_HOST_QUE_NO_EXISTE, 5432, DependencyProbe::BUDGET_SECONDS),
    ));

    WallClockBudget::expectMedianBelow($muestras, READINESS_BUDGET_TECHO_SEGUNDOS, 'RNF-D-03');
})->group('RNF-D-03', 'RF-PD-13', 'performance');

it('no da por alcanzable un puerto cerrado', function (): void {
    expect((new BoundedReachability)->reachable('127.0.0.1', 1, 1.0))->toBeFalse();
})->group('RNF-D-03');

it('da por alcanzable un puerto que acepta conexiones', function (): void {
    $servidor = stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensaje);

    expect($servidor)->not->toBeFalse();

    /** @var resource $servidor */
    $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($servidor, false), ':'), 1);

    try {
        expect((new BoundedReachability)->reachable('127.0.0.1', $puerto, 1.0))->toBeTrue()
            ->and((new BoundedReachability)->reachable('localhost', $puerto, 1.0))->toBeTrue();
    } finally {
        fclose($servidor);
    }
})->group('RNF-D-03');

it('da el fallo de PostgreSQL con su nombre desaparecido del DNS (R3-CH-02)', function (): void {
    readinessBudgetPostgresDesaparecido();

    $fallo = resolve(DependencyProbe::class)->firstFailure();

    expect($fallo?->component)->toBe('database')
        ->and($fallo?->failure)->toBe(EndpointUnreachable::class);
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

it('da el fallo de PostgreSQL en menos de 3 s con su nombre desaparecido del DNS, en mediana (R3-CH-02)', function (): void {
    readinessBudgetPostgresDesaparecido();

    $muestras = WallClockBudget::samples(static fn (): float => WallClockBudget::seconds(
        static fn () => resolve(DependencyProbe::class)->firstFailure(),
    ));

    WallClockBudget::expectMedianBelow($muestras, READINESS_BUDGET_TECHO_SEGUNDOS, 'RNF-D-03');
})->group('RNF-D-03', 'RF-PD-13', 'performance');

it('responde 503 con el nombre de PostgreSQL desaparecido, por HTTP (R3-CH-02)', function (): void {
    readinessBudgetPostgresDesaparecido();

    Api::guest()->get('/api/v1/ready')
        ->assertStatus(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-ready');
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

it('responde 503 en menos de 3 s con el nombre de PostgreSQL desaparecido, por HTTP, en mediana (R3-CH-02)', function (): void {
    readinessBudgetPostgresDesaparecido();

    $muestras = WallClockBudget::samples(static fn (): float => WallClockBudget::seconds(
        static fn () => Api::guest()->get('/api/v1/ready')->assertStatus(503),
    ));

    WallClockBudget::expectMedianBelow($muestras, READINESS_BUDGET_TECHO_SEGUNDOS, 'RNF-D-03');
})->group('RNF-D-03', 'RF-PD-13', 'performance');

/*
 * Redis se mide sobre la sonda y no por HTTP: la peticion de prueba escribe
 * ademas sus metricas HTTP en ese mismo Redis al terminar, con el cortacircuitos
 * apagado en la suite, y eso es la peticion y no la sonda. En produccion ese
 * apunte ocurre despues de enviar la respuesta, y el circuito ya lo abrio la
 * propia sonda (la prueba siguiente).
 */
it('da el fallo de Redis con su nombre desaparecido del DNS, sin host ni puerto (R3-CH-02)', function (): void {
    readinessBudgetRedisDesaparecido();

    $fallo = resolve(DependencyProbe::class)->firstFailure();

    expect($fallo?->component)->toBe('redis')
        ->and($fallo?->failure)->toBe(EndpointUnreachable::class)
        ->and((string) json_encode($fallo))->not->toContain('invalid');
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

it('da el fallo de Redis en menos de 3 s con su nombre desaparecido del DNS, en mediana (R3-CH-02)', function (): void {
    $muestras = WallClockBudget::samples(static function (): float {
        readinessBudgetRedisDesaparecido();

        return WallClockBudget::seconds(static fn () => resolve(DependencyProbe::class)->firstFailure());
    });

    WallClockBudget::expectMedianBelow($muestras, READINESS_BUDGET_TECHO_SEGUNDOS, 'RNF-D-03');
})->group('RNF-D-03', 'RF-PD-13', 'performance');

it('cuando la sonda ve Redis inalcanzable abre el cortacircuitos para las peticiones (R3-CH-01)', function (): void {
    readinessBudgetRedisDesaparecido();

    readinessBudgetConCortacircuitosDeRedis(static function (RedisCircuitBreaker $breaker): void {
        resolve(DependencyProbe::class)->firstFailure();

        $segundo = resolve(DependencyProbe::class)->firstFailure();

        expect($breaker->state())->toBe(CircuitState::Open)
            ->and($segundo?->failure)->toBe(RedisCircuitOpen::class);
    });
})->group('RNF-D-03', 'RF-AT-10');

it('con el cortacircuitos abierto la segunda sonda ya no gasta el tope, en mediana (R3-CH-01)', function (): void {
    // El PING falla al instante: medio segundo de techo frente a los 2 s del tope.
    $muestras = WallClockBudget::samples(static function (): float {
        readinessBudgetRedisDesaparecido();

        return readinessBudgetConCortacircuitosDeRedis(static function (): float {
            resolve(DependencyProbe::class)->firstFailure();

            return WallClockBudget::seconds(static fn () => resolve(DependencyProbe::class)->firstFailure());
        });
    });

    WallClockBudget::expectMedianBelow($muestras, 0.5, 'RNF-D-03');
})->group('RNF-D-03', 'RF-AT-10', 'performance');
