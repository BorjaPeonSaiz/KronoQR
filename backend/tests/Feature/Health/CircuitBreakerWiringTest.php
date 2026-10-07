<?php

declare(strict_types=1);

use App\Support\Database\CircuitBreakingPostgresConnector;
use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\CircuitBreakingPhpRedisConnector;
use App\Support\Redis\RedisCircuitBreaker;
use App\Support\Redis\RedisCircuitOpen;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;

/*
 * El cableado de PRODUCCION de los dos cortacircuitos, sin sustituir nada
 * (R3-CH-01, R3-CH-02).
 *
 * Las demas pruebas de los cortacircuitos ponen su propio conector para contar
 * intentos, y por eso no verian que el enganche de `AppServiceProvider` se ha
 * perdido: un `extend()` con un closure `static` —lo que propondria cualquier
 * modernizador— deja al framework con su conector de serie sin decir nada, y
 * un `db.connector.pgsql` mal escrito tambien. Aqui solo se enciende el
 * cortacircuitos por configuracion, se deja abierto en su fichero y se pide la
 * conexion por el camino normal.
 */

const CIRCUIT_BREAKER_WIRING_SEGUNDOS = 10.0;

function circuitBreakerWiringFichero(string $dependencia): string
{
    return sys_get_temp_dir().'/kronoqr-'.$dependencia.'-circuit-wiring-'.getmypid();
}

/**
 * El circuito abierto como lo dejaria otro proceso: un instante dentro del
 * plazo en el fichero de estado.
 */
function circuitBreakerWiringAbierto(string $fichero): void
{
    file_put_contents($fichero, \sprintf('%.6F', microtime(true) + CIRCUIT_BREAKER_WIRING_SEGUNDOS / 2));
}

afterEach(function (): void {
    foreach (['redis', 'database'] as $dependencia) {
        $fichero = circuitBreakerWiringFichero($dependencia);

        if (is_file($fichero)) {
            unlink($fichero);
        }
    }

    foreach (['redis', Redis::class, RedisCircuitBreaker::class, DatabaseCircuitBreaker::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    DB::purge('pgsql_cableado');
});

it('el gestor de Redis de produccion pasa por el cortacircuitos (R3-CH-01)', function (): void {
    config()->set('database.redis_circuit_breaker.seconds', CIRCUIT_BREAKER_WIRING_SEGUNDOS);
    config()->set('database.redis_circuit_breaker.state_file', circuitBreakerWiringFichero('redis'));
    circuitBreakerWiringAbierto(circuitBreakerWiringFichero('redis'));

    foreach (['redis', Redis::class, RedisCircuitBreaker::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    expect(static fn (): mixed => app('redis')->connection('default'))->toThrow(RedisCircuitOpen::class);
})->group('RNF-D-03', 'RF-AT-10');

it('las conexiones de PostgreSQL de produccion pasan por el cortacircuitos (R3-CH-02)', function (): void {
    config()->set('database.database_circuit_breaker.seconds', CIRCUIT_BREAKER_WIRING_SEGUNDOS);
    config()->set('database.database_circuit_breaker.state_file', circuitBreakerWiringFichero('database'));
    circuitBreakerWiringAbierto(circuitBreakerWiringFichero('database'));
    app()->forgetInstance(DatabaseCircuitBreaker::class);

    // Una conexion nueva, para que no la sirva un PDO ya abierto.
    config()->set('database.connections.pgsql_cableado', config()->array('database.connections.pgsql'));

    expect(app('db.connector.pgsql'))->toBeInstanceOf(CircuitBreakingPostgresConnector::class);

    $fallo = null;

    try {
        DB::connection('pgsql_cableado')->select('select 1');
    } catch (Throwable $exception) {
        $fallo = $exception;
    }

    expect($fallo)->toBeInstanceOf(Throwable::class)
        ->and(DatabaseUnavailable::foundIn($fallo ?? new RuntimeException))->toBeInstanceOf(DatabaseUnavailable::class);
})->group('RNF-D-03', 'RF-AT-10');

it('el conector de Redis de produccion es el del cortacircuitos', function (): void {
    // El fallo de arriba podria venir de otra cosa; esto ata el sintoma al
    // enganche concreto.
    $conector = (fn (): mixed => $this->connector())->call(app('redis'));

    expect($conector)->toBeInstanceOf(CircuitBreakingPhpRedisConnector::class);
})->group('RNF-D-03');
