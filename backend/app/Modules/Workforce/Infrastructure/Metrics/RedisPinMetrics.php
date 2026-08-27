<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Metrics;

use App\Modules\Workforce\Application\Port\PinMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `pin_resets_total{site}` sobre Redis (doc 02 §8.2, tarea 1.13).
 *
 * **Redis y no el colector *textfile*, por lo mismo que
 * `Attendance\Infrastructure\Metrics\RedisScanMetrics`** —nombrado en prosa y no
 * con `@see`, porque una referencia resoluble seria una dependencia entre
 * modulos que la frontera del §1.6 no concede—: lo
 * incrementa una peticion HTTP de un proceso que termina, y un contador en
 * memoria de un proceso que termina no lo lee nadie. `HINCRBY` es atomico y ya
 * hay Redis en el stack. El endpoint `/metrics` que lo publica es de la tarea
 * 3.1; hasta entonces el contador se acumula y las pruebas lo leen.
 *
 * **La unica etiqueta es el centro** (regla dura 21): nunca el empleado cuyo PIN
 * se restablecio. Una serie por persona seria un registro paralelo de a quien se
 * le olvida el PIN, sin retencion ni control de acceso.
 *
 * **Medir no puede romper un restablecimiento.** Si Redis no responde, el PIN ya
 * esta emitido y confirmado: perder el contador es infinitamente mas barato que
 * devolver un `500` a quien acaba de recibir un PIN que ya es valido, y que
 * volveria a pulsar el boton generando otro.
 */
final readonly class RedisPinMetrics implements PinMetrics
{
    /** Mismo prefijo que el resto de metricas para que la tarea 3.1 las reuna con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string PIN_RESETS_TOTAL = self::KEY_PREFIX.'pin_resets_total';

    public function __construct(private Redis $redis) {}

    public function pinReset(int $siteId): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::PIN_RESETS_TOTAL,
                'site='.$siteId,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: el PIN ya esta
            // emitido cuando se llega aqui.
        }
    }
}
