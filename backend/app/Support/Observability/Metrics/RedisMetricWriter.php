<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * La mecanica de escritura de las tres series **transversales** del §8.2 —las
 * de colas y las de base de datos—, que no pertenecen a ningun modulo y por
 * tanto no tienen puerto ni adaptador propio.
 *
 * Es el mismo argumento de `Shared\Infrastructure\Metrics\TextfileExposition`
 * aplicado al otro soporte: mientras el metodo exista, no hay forma de escribir
 * una de estas series con un formato que el lector de `/metrics` no entienda, ni
 * de escribir un histograma sin su `+Inf`.
 *
 * **Medir nunca rompe nada** (regla dura 19). Todo va envuelto: quien llama a
 * esto esta en el camino de una peticion o de un trabajo en cola, y ninguno de
 * los dos puede fallar porque Redis no conteste.
 */
final readonly class RedisMetricWriter
{
    public function __construct(private Redis $redis) {}

    /**
     * Un contador o un *gauge* con etiquetas: un HASH por serie, la combinacion
     * de etiquetas como campo.
     */
    public function increment(string $series, string $labels, int $by = 1): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                MetricCatalogue::KEY_PREFIX.$series,
                $labels,
                $by,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    /**
     * Una observacion de histograma: `sum`, `count` y los cubos que la
     * contienen, mas el `+Inf` obligatorio.
     *
     * **Con `$count` mayor que uno** para poder volcar de golpe lo acumulado en
     * memoria durante una peticion: `db_query_duration_seconds` se escribe una
     * sola vez al terminar, no una vez por consulta (decision 4 de la ficha
     * 3.1). Un `HINCRBY` por consulta doblaria los viajes a Redis del camino de
     * fichaje.
     *
     * @param  list<float>  $bounds  Cubos de la serie, en segundos y crecientes.
     * @param  list<int>  $bucketCounts  Observaciones ya repartidas por cubo, **en el
     *                                   mismo orden que `$bounds`**. Por posicion y no por
     *                                   el limite como clave: `(string) 1.0` es `'1'`, que
     *                                   PHP convierte en la clave entera `1`, y un mapa con
     *                                   claves de dos tipos es un error esperando a que
     *                                   alguien añada un cubo redondo.
     */
    public function observeMany(
        string $series,
        string $labels,
        array $bounds,
        array $bucketCounts,
        float $sum,
        int $count,
    ): void {
        if ($count < 1) {
            return;
        }

        try {
            $connection = $this->redis->connection();
            $key = MetricCatalogue::KEY_PREFIX.$series.($labels === '' ? '' : ':'.$labels);

            $connection->command('HINCRBYFLOAT', [$key, 'sum', $sum]);
            $connection->command('HINCRBY', [$key, 'count', $count]);

            foreach ($bounds as $index => $bound) {
                $inBucket = $bucketCounts[$index] ?? 0;

                if ($inBucket > 0) {
                    $connection->command('HINCRBY', [$key, 'le='.$bound, $inBucket]);
                }
            }

            // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
            // coincidir con `count`: sin el, `histogram_quantile()` devuelve NaN.
            $connection->command('HINCRBY', [$key, 'le=+Inf', $count]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    /**
     * Reparte una duracion entre los cubos que la contienen y devuelve el
     * recuento que espera {@see self::observeMany()}, **por posicion**.
     *
     * Acumulativo a proposito: en un histograma de Prometheus una observacion
     * suma en su cubo y en todos los mayores, que es lo que hace que
     * `_bucket{le="0.1"}` signifique «cuantas tardaron 0,1 s o menos».
     *
     * @param  list<float>  $bounds
     * @param  list<int>  $into  Recuento anterior; vacio empieza de cero.
     * @return list<int>
     */
    public static function tallyBuckets(array $bounds, float $seconds, array $into = []): array
    {
        $tally = [];

        foreach ($bounds as $index => $bound) {
            $tally[] = ($into[$index] ?? 0) + ($seconds <= $bound ? 1 : 0);
        }

        return $tally;
    }
}
