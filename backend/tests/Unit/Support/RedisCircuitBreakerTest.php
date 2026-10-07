<?php

declare(strict_types=1);

use App\Support\Redis\RedisCircuitBreaker;
use App\Support\Resilience\CircuitState;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Observability\RecordingLogger;

/*
 * El cortacircuitos de Redis, sin Redis y sin aplicacion (R3-CH-01).
 *
 * Lo que se afirma es el reloj del circuito: cuando se abre, cuanto dura
 * abierto, que lo comparten los procesos —el fichero de estado— y que se cierra
 * solo cuando Redis vuelve a contestar. El reloj es un `MockClock`: el TTL se
 * prueba moviendo el tiempo, no esperandolo.
 */

function redisCircuitBreakerTestFile(): string
{
    $file = sys_get_temp_dir().'/kronoqr-redis-circuit-unit-'.getmypid().'-'.bin2hex(random_bytes(4));

    if (is_file($file)) {
        unlink($file);
    }

    return $file;
}

function redisCircuitBreakerTestBreaker(string $file, MockClock $clock, RecordingLogger $logger, float $seconds = 10.0): RedisCircuitBreaker
{
    return new RedisCircuitBreaker($file, $seconds, $clock, $logger);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-redis-circuit-unit-*') ?: [] as $file) {
        unlink($file);
    }
});

it('empieza cerrado y sin fichero de estado', function (): void {
    $file = redisCircuitBreakerTestFile();
    $breaker = redisCircuitBreakerTestBreaker($file, new MockClock('2026-10-07 09:00:00'), new RecordingLogger);

    expect($breaker->state())->toBe(CircuitState::Closed)
        ->and(is_file($file))->toBeFalse();
})->group('RNF-D-03');

it('se abre con el primer fallo y no deja reintentar hasta que pasa el plazo', function (): void {
    $file = redisCircuitBreakerTestFile();
    $clock = new MockClock('2026-10-07 09:00:00');
    $breaker = redisCircuitBreakerTestBreaker($file, $clock, new RecordingLogger);

    $breaker->recordFailure(new RedisException('Connection refused'));

    expect($breaker->state())->toBe(CircuitState::Open);

    $clock->sleep(9.9);
    expect($breaker->state())->toBe(CircuitState::Open);

    // Pasado el plazo no se cierra: se entreabre, y la siguiente conexion hace
    // primero una comprobacion acotada.
    $clock->sleep(0.2);
    expect($breaker->state())->toBe(CircuitState::HalfOpen);
})->group('RNF-D-03', 'RF-AT-10');

it('lo recuerda entre procesos por el fichero de estado', function (): void {
    // Dos instancias con el mismo fichero son dos procesos de PHP-FPM: la
    // segunda no ha visto el fallo y aun asi no reintenta.
    $file = redisCircuitBreakerTestFile();
    $clock = new MockClock('2026-10-07 09:00:00');

    redisCircuitBreakerTestBreaker($file, $clock, new RecordingLogger)
        ->recordFailure(new RedisException('Connection refused'));

    $otroProceso = redisCircuitBreakerTestBreaker($file, $clock, new RecordingLogger);

    expect(is_file($file))->toBeTrue()
        ->and($otroProceso->state())->toBe(CircuitState::Open);
})->group('RNF-D-03', 'RF-AT-10');

it('se cierra cuando Redis vuelve a contestar y borra el fichero', function (): void {
    $file = redisCircuitBreakerTestFile();
    $clock = new MockClock('2026-10-07 09:00:00');
    $logger = new RecordingLogger;
    $breaker = redisCircuitBreakerTestBreaker($file, $clock, $logger);

    $breaker->recordFailure(new RedisException('Connection refused'));
    $clock->sleep(11);
    $breaker->recordSuccess();

    expect($breaker->state())->toBe(CircuitState::Closed)
        ->and(is_file($file))->toBeFalse()
        ->and(array_column($logger->lines, 'message'))->toBe(['redis.circuit_opened', 'redis.circuit_closed']);
})->group('RNF-D-03');

it('avisa una vez por averia aunque se reabra en cada comprobacion fallida', function (): void {
    $file = redisCircuitBreakerTestFile();
    $clock = new MockClock('2026-10-07 09:00:00');
    $logger = new RecordingLogger;
    $breaker = redisCircuitBreakerTestBreaker($file, $clock, $logger);

    for ($vuelta = 0; $vuelta < 4; $vuelta++) {
        $breaker->recordFailure(new RedisException('Connection refused'));
        $clock->sleep(11);
        expect($breaker->state())->toBe(CircuitState::HalfOpen);
    }

    expect(array_column($logger->lines, 'message'))->toBe(['redis.circuit_opened']);
})->group('RNF-D-03');

it('registra la clase de la excepcion y nunca su mensaje', function (): void {
    // El mensaje de phpredis lleva host y puerto (regla dura 21).
    $logger = new RecordingLogger;
    $breaker = redisCircuitBreakerTestBreaker(redisCircuitBreakerTestFile(), new MockClock, $logger);

    $breaker->recordFailure(new RedisException('Connection refused [tcp://redis:6379]'));

    expect($logger->first()['context'])->toBe(['failure' => RedisException::class, 'open_seconds' => 10.0])
        ->and(json_encode($logger->lines))->not->toContain('6379');
})->group('RNF-D-03');

it('con plazo cero no hace nada: ni abre ni escribe', function (): void {
    $file = redisCircuitBreakerTestFile();
    $logger = new RecordingLogger;
    $breaker = redisCircuitBreakerTestBreaker($file, new MockClock, $logger, seconds: 0.0);

    $breaker->recordFailure(new RedisException('Connection refused'));

    expect($breaker->state())->toBe(CircuitState::Closed)
        ->and(is_file($file))->toBeFalse()
        ->and($logger->lines)->toBe([]);
})->group('RNF-D-03');

it('un fichero de estado ilegible cuenta como circuito cerrado', function (): void {
    // Un disco que devuelve basura degrada el cortacircuitos, nunca la peticion.
    $file = redisCircuitBreakerTestFile();
    file_put_contents($file, 'no-es-un-numero');

    $breaker = redisCircuitBreakerTestBreaker($file, new MockClock, new RecordingLogger);

    expect($breaker->state())->toBe(CircuitState::Closed);
})->group('RNF-D-03');

it('una ruta de estado que no se puede escribir no rompe nada y recuerda en memoria', function (): void {
    $breaker = redisCircuitBreakerTestBreaker('/proc/no-se-puede-escribir/aqui', new MockClock, new RecordingLogger);

    $breaker->recordFailure(new RedisException('Connection refused'));

    expect($breaker->state())->toBe(CircuitState::Open);
})->group('RNF-D-03', 'RF-AT-10');
