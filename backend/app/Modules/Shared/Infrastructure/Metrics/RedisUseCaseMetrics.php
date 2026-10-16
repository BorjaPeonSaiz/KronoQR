<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics;

use App\Modules\Shared\Application\Port\UseCaseMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Throwable;

/**
 * `use_case_duration_seconds{use_case,outcome}` sobre Redis (doc 02 §8.2).
 *
 * Mismo formato que `queue_job_duration_seconds`: un HASH por combinacion de
 * etiquetas (`…:use_case=workforce.register_employee,outcome=ok`) con `sum`,
 * `count`, un campo `le=` por cubo y el `+Inf` obligatorio. `/metrics` lo
 * publica con `MetricCatalogue`.
 *
 * ## Las etiquetas se validan aqui, y lo que no casa se descarta
 *
 * `use_case` tiene que ser `<modulo>.<accion_snake>` en minusculas y `outcome`,
 * `ok`, `rejected` o `error`. Es la ultima puerta antes de que algo se
 * convierta en una serie: una coma, un igual o un espacio romperian la
 * descomposicion de las etiquetas en el lector, y una cadena libre podria
 * acabar llevando un dato de una persona a un sistema sin control de acceso
 * (regla dura 21). Descartar y no lanzar: una observacion perdida no vale un
 * caso de uso roto.
 *
 * ## Un solo viaje, y nunca con una transaccion abierta
 *
 * Una importacion de plantilla registra cada fila con un caso de uso medido
 * **dentro** de una transaccion que tiene tomado el candado global de la
 * cadena de `audit_log` (ADR-010): cada milisegundo ahi dentro lo espera el
 * siguiente fichaje. Por eso:
 *
 * - **Con una transaccion abierta, la escritura se aplaza** a cuando termina.
 *   Se escribe tras el `COMMIT` y **tambien tras el `ROLLBACK`**: un caso de
 *   uso que termino en `rejected` o `error` y arrastro la transaccion es
 *   justamente la observacion que no se puede perder. Cada observacion se
 *   escribe una sola vez, la dispare lo que la dispare.
 * - **Todo va en un solo viaje** a Redis (`pipeline` con phpredis): doce
 *   comandos, una ida y vuelta.
 *
 * Se escriben **todos** los cubos, con 0 o 1, y no solo los que contienen la
 * duracion: en el mismo viaje no cuesta nada y la serie nace con todos sus
 * `le` desde la primera observacion.
 */
final readonly class RedisUseCaseMetrics implements UseCaseMetrics
{
    /** Mismo prefijo que el resto de metricas para que `/metrics` las reuna con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string DURATION = self::KEY_PREFIX.'use_case_duration_seconds';

    public const string USE_CASE_PATTERN = '/^[a-z]+\.[a-z0-9_]+$/';

    /** @var list<string> */
    public const array OUTCOMES = ['ok', 'rejected', 'error'];

    /**
     * Cubos en segundos. Un caso de uso va de una lectura de milisegundos a una
     * importacion de plantilla de un minuto: los cubos cubren las dos escalas
     * sin pasar de nueve series por combinacion.
     *
     * @var list<float>
     */
    public const array BUCKETS = [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 5.0, 30.0];

    /**
     * @param  ConnectionResolverInterface|null  $database  La conexion cuya transaccion se
     *                                                      espera. `null` escribe en el acto: es lo que usan las pruebas sin base de datos.
     */
    public function __construct(
        private Redis $redis,
        private ?ConnectionResolverInterface $database = null,
    ) {}

    public function observe(string $useCase, string $outcome, float $seconds): void
    {
        if (preg_match(self::USE_CASE_PATTERN, $useCase) !== 1
            || ! \in_array($outcome, self::OUTCOMES, true)
            || ! is_finite($seconds)
            || $seconds < 0.0) {
            return;
        }

        $write = $this->once(fn () => $this->write($useCase, $outcome, $seconds));

        try {
            $connection = $this->database?->connection();

            if ($connection instanceof Connection && $connection->transactionLevel() > 0) {
                $connection->afterCommit($write);
                $connection->afterRollBack($write);

                return;
            }
        } catch (Throwable) {
            // Sin poder preguntar por la transaccion se escribe en el acto: es
            // el comportamiento sin base de datos, y perder la espera es mejor
            // que perder la observacion.
        }

        $write();
    }

    /**
     * El cierre que se registra a la vez para el `COMMIT` y para el `ROLLBACK`:
     * solo uno de los dos deberia dispararse, y esto lo garantiza aunque Laravel
     * propague los dos al nivel exterior al confirmar un punto de guardado.
     *
     * @param  callable(): void  $write
     * @return callable(): void
     */
    private function once(callable $write): callable
    {
        $done = false;

        return static function () use (&$done, $write): void {
            if ($done) {
                return;
            }

            $done = true;
            $write();
        };
    }

    private function write(string $useCase, string $outcome, float $seconds): void
    {
        $key = self::DURATION.':use_case='.$useCase.',outcome='.$outcome;

        /** @var array<string, int> $increments */
        $increments = ['count' => 1];

        foreach (self::BUCKETS as $bound) {
            $increments['le='.$bound] = $seconds <= $bound ? 1 : 0;
        }

        // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
        // coincidir con `count`: sin el, `histogram_quantile()` devuelve NaN.
        $increments['le=+Inf'] = 1;

        try {
            $this->send($this->redis->connection(), $key, $seconds, $increments);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    /**
     * Por los metodos con nombre del cliente y no por `rawCommand`: estos son
     * los que aplican el prefijo de claves de la instalacion (`REDIS_PREFIX`).
     *
     * @param  array<string, int>  $increments
     */
    private function send(RedisConnection $connection, string $key, float $sum, array $increments): void
    {
        if ($connection instanceof PhpRedisConnection) {
            $connection->pipeline(static function (mixed $pipe) use ($key, $sum, $increments): void {
                if (! $pipe instanceof \Redis) {
                    return;
                }

                $pipe->hIncrByFloat($key, 'sum', $sum);

                foreach ($increments as $field => $by) {
                    $pipe->hIncrBy($key, $field, $by);
                }
            });

            return;
        }

        // Otro cliente (Predis): mismo resultado, un viaje por comando.
        $connection->command('HINCRBYFLOAT', [$key, 'sum', $sum]);

        foreach ($increments as $field => $by) {
            $connection->command('HINCRBY', [$key, $field, $by]);
        }
    }
}
