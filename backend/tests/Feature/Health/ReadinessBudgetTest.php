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
 */

const READINESS_BUDGET_HOST_QUE_NO_EXISTE = 'kronoqr-dependencia-desaparecida.invalid';

const READINESS_BUDGET_TECHO_SEGUNDOS = 3.0;

it('no da por alcanzable un nombre que no resuelve, y lo dice antes de agotar el tope', function (): void {
    $inicio = microtime(true);

    $alcanzable = (new BoundedReachability)->reachable(READINESS_BUDGET_HOST_QUE_NO_EXISTE, 5432, DependencyProbe::BUDGET_SECONDS);

    expect($alcanzable)->toBeFalse()
        ->and(microtime(true) - $inicio)->toBeLessThan(READINESS_BUDGET_TECHO_SEGUNDOS);
})->group('RNF-D-03', 'RF-PD-13');

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

it('da el fallo de PostgreSQL en menos de 3 s con su nombre desaparecido del DNS (R3-CH-02)', function (): void {
    config()->set('database.connections.pgsql_desaparecida', [
        ...config()->array('database.connections.pgsql'),
        'host' => READINESS_BUDGET_HOST_QUE_NO_EXISTE,
    ]);
    config()->set('database.default', 'pgsql_desaparecida');

    $inicio = microtime(true);

    $fallo = resolve(DependencyProbe::class)->firstFailure();

    expect(microtime(true) - $inicio)->toBeLessThan(READINESS_BUDGET_TECHO_SEGUNDOS)
        ->and($fallo?->component)->toBe('database')
        ->and($fallo?->failure)->toBe(EndpointUnreachable::class);
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

it('responde 503 en menos de 3 s con el nombre de PostgreSQL desaparecido, por HTTP (R3-CH-02)', function (): void {
    config()->set('database.connections.pgsql_desaparecida', [
        ...config()->array('database.connections.pgsql'),
        'host' => READINESS_BUDGET_HOST_QUE_NO_EXISTE,
    ]);
    config()->set('database.default', 'pgsql_desaparecida');

    $inicio = microtime(true);

    Api::guest()->get('/api/v1/ready')
        ->assertStatus(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-ready');

    expect(microtime(true) - $inicio)->toBeLessThan(READINESS_BUDGET_TECHO_SEGUNDOS);
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

/*
 * Redis se mide sobre la sonda y no por HTTP: la peticion de prueba escribe
 * ademas sus metricas HTTP en ese mismo Redis al terminar, con el cortacircuitos
 * apagado en la suite, y eso es la peticion y no la sonda. En produccion ese
 * apunte ocurre despues de enviar la respuesta, y el circuito ya lo abrio la
 * propia sonda (la prueba siguiente).
 */
it('da el fallo de Redis en menos de 3 s con su nombre desaparecido del DNS, sin host ni puerto (R3-CH-02)', function (): void {
    config()->set('database.redis.default.host', READINESS_BUDGET_HOST_QUE_NO_EXISTE);
    app()->forgetInstance('redis');
    app()->forgetInstance(Redis::class);

    $inicio = microtime(true);

    $fallo = resolve(DependencyProbe::class)->firstFailure();

    expect(microtime(true) - $inicio)->toBeLessThan(READINESS_BUDGET_TECHO_SEGUNDOS)
        ->and($fallo?->component)->toBe('redis')
        ->and($fallo?->failure)->toBe(EndpointUnreachable::class)
        ->and((string) json_encode($fallo))->not->toContain('invalid');
})->group('RNF-D-03', 'RF-PD-13', 'RQ-06');

it('cuando la sonda ve Redis inalcanzable abre el cortacircuitos para las peticiones (R3-CH-01)', function (): void {
    $fichero = sys_get_temp_dir().'/kronoqr-redis-circuit-readiness-'.getmypid();
    $breaker = new RedisCircuitBreaker($fichero, 10.0, new MockClock, resolve(LoggerInterface::class));
    app()->instance(RedisCircuitBreaker::class, $breaker);

    config()->set('database.redis.default.host', READINESS_BUDGET_HOST_QUE_NO_EXISTE);
    app()->forgetInstance('redis');
    app()->forgetInstance(Redis::class);

    try {
        resolve(DependencyProbe::class)->firstFailure();

        // Y la segunda sonda ya no gasta el tope: el PING falla al instante.
        $inicio = microtime(true);
        $segundo = resolve(DependencyProbe::class)->firstFailure();

        expect($breaker->state())->toBe(CircuitState::Open)
            ->and($segundo?->failure)->toBe(RedisCircuitOpen::class)
            ->and(microtime(true) - $inicio)->toBeLessThan(0.5);
    } finally {
        if (is_file($fichero)) {
            unlink($fichero);
        }
    }
})->group('RNF-D-03', 'RF-AT-10');
