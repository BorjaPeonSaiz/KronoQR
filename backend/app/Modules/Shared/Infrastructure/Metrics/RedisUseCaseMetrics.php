<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics;

use App\Modules\Shared\Application\Port\UseCaseMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
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
 * `ok` o `error`. Es la ultima puerta antes de que algo se convierta en una
 * serie: una coma, un igual o un espacio romperian la descomposicion de las
 * etiquetas en el lector, y una cadena libre podria acabar llevando un dato de
 * una persona a un sistema sin control de acceso (regla dura 21). Descartar y
 * no lanzar: una observacion perdida no vale un caso de uso roto.
 */
final readonly class RedisUseCaseMetrics implements UseCaseMetrics
{
    /** Mismo prefijo que el resto de metricas para que `/metrics` las reuna con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string DURATION = self::KEY_PREFIX.'use_case_duration_seconds';

    public const string USE_CASE_PATTERN = '/^[a-z]+\.[a-z0-9_]+$/';

    /**
     * Cubos en segundos. Un caso de uso va de una lectura de milisegundos a una
     * importacion de plantilla de un minuto: los cubos cubren las dos escalas
     * sin pasar de nueve series por combinacion.
     *
     * @var list<float>
     */
    public const array BUCKETS = [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 5.0, 30.0];

    public function __construct(private Redis $redis) {}

    public function observe(string $useCase, string $outcome, float $seconds): void
    {
        if (preg_match(self::USE_CASE_PATTERN, $useCase) !== 1
            || ! \in_array($outcome, ['ok', 'error'], true)
            || ! is_finite($seconds)
            || $seconds < 0.0) {
            return;
        }

        try {
            $connection = $this->redis->connection();
            $key = self::DURATION.':use_case='.$useCase.',outcome='.$outcome;

            $connection->command('HINCRBYFLOAT', [$key, 'sum', $seconds]);
            $connection->command('HINCRBY', [$key, 'count', 1]);

            // TODOS los cubos, con 0 o 1, y no solo los que contienen la
            // duracion: asi el numero de viajes a Redis no depende de lo que
            // tardo el caso de uso. Un rechazo rapido de autenticacion no puede
            // costar mas escrituras que un acierto lento (RS-03).
            foreach (self::BUCKETS as $bound) {
                $connection->command('HINCRBY', [$key, 'le='.$bound, $seconds <= $bound ? 1 : 0]);
            }

            // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
            // coincidir con `count`: sin el, `histogram_quantile()` devuelve NaN.
            $connection->command('HINCRBY', [$key, 'le=+Inf', 1]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
