<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use App\Support\Network\BoundedReachability;
use App\Support\Redis\RedisCircuitBreaker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RedisException;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Health\CountingRedisConnector;
use Tests\Support\Health\RedisOutage;

/**
 * Una dependencia caida de verdad para el barrido del `503` (ADR-059, Q3).
 *
 * Las dos, como las dejan las pruebas del bloque 10:
 *
 * - **PostgreSQL** en un puerto cerrado (`127.0.0.1:1`, conexion rechazada al
 *   instante), la conexion de la aplicacion y la del historico de errores, como
 *   `ScanWithDatabaseCircuitOpenTest`. Sin datos: la primera consulta de una
 *   ruta con sesion es la del token, y ahi ya cae.
 * - **Redis** en un puerto cerrado y con su circuito abierto, como
 *   `DependencyUnavailableTest`: `RedisOutage` y el conector que cuenta.
 *
 * La prueba que la use NO puede envolverse en `RefreshDatabase`: con la base
 * tirada, la transaccion de la prueba no se puede revertir. Tampoco lo
 * necesita, porque con la dependencia caida no se escribe nada.
 */
final class DependencyOutage
{
    private const array DATABASE_CONNECTIONS = ['pgsql', 'error_events'];

    /** @var array<string, mixed> */
    private static array $databaseConfiguration = [];

    private static bool $redisDown = false;

    public static function takeDown(string $dependency): void
    {
        match ($dependency) {
            'postgres' => self::postgres(),
            'redis' => self::redis(),
            default => throw new InvalidArgumentException('Dependencia desconocida: '.$dependency.'.'),
        };
    }

    /**
     * Devuelve las dos a su sitio. Se llama en el `afterEach`, haya caido una o
     * ninguna.
     */
    public static function restore(): void
    {
        foreach (self::$databaseConfiguration as $connection => $configuration) {
            config()->set('database.connections.'.$connection, $configuration);
            DB::purge($connection);
        }

        self::$databaseConfiguration = [];

        if (self::$redisDown) {
            RedisOutage::end();
            self::$redisDown = false;
        }

        if (is_file(self::redisCircuitFile())) {
            unlink(self::redisCircuitFile());
        }
    }

    private static function postgres(): void
    {
        foreach (self::DATABASE_CONNECTIONS as $connection) {
            self::$databaseConfiguration[$connection] = config('database.connections.'.$connection);
            config()->set('database.connections.'.$connection.'.host', '127.0.0.1');
            config()->set('database.connections.'.$connection.'.port', 1);
            DB::purge($connection);
        }
    }

    private static function redis(): void
    {
        RedisOutage::begin();
        self::$redisDown = true;

        $breaker = new RedisCircuitBreaker(self::redisCircuitFile(), 10.0, new MockClock, resolve(LoggerInterface::class));
        app()->instance(RedisCircuitBreaker::class, $breaker);
        resolve('redis')->extend('phpredis', fn (): CountingRedisConnector => new CountingRedisConnector($breaker, new BoundedReachability));
        $breaker->recordFailure(new RedisException('Connection refused'));
    }

    private static function redisCircuitFile(): string
    {
        return sys_get_temp_dir().'/kronoqr-redis-circuit-contract-sweep-'.getmypid();
    }
}
