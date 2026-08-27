<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Metrics;

use App\Modules\Kiosk\Application\Port\KioskMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `kiosk_last_seen_seconds{device}` y `kiosk_offline_queue_size{device}` sobre
 * Redis (doc 02 §8.2).
 *
 * **Redis y no el colector *textfile***, por lo mismo que
 * `Attendance\Infrastructure\Metrics\RedisScanMetrics`: el hecho medido lo
 * produce una peticion HTTP, no un comando diario, y un fichero reescrito en cada
 * latido seria una escritura de disco por minuto y por quiosco, con una carrera
 * entre procesos PHP de regalo. El endpoint `/metrics` que lo publica es de la
 * tarea 3.1; hasta entonces los valores se acumulan y las pruebas los leen.
 *
 * **`HSET` y no `HINCRBY`: son gauges.** Lo que interesa no es cuantos latidos
 * hubo sino **cual fue el ultimo** y **cuanto hay en la cola ahora**. Un contador
 * aqui no responderia ninguna de las dos preguntas, y la alerta «quiosco sin
 * latido > 10 min» del doc 01 §9.3 se construye sobre la primera.
 *
 * **Se publica el instante, no la antiguedad.** La resta la hace Prometheus
 * (`time() - kiosk_last_seen_seconds`), que es lo que mantiene la metrica correcta
 * cuando nadie ficha: una antiguedad calculada en el ultimo latido se quedaria
 * congelada justo cuando el quiosco deja de latir, que es cuando la alerta tiene
 * que dispararse.
 *
 * **Medir no puede romper un latido**, igual que en el fichaje: si Redis no
 * responde, el latido sigue su camino. La alternativa —devolver un `500` a una
 * tablet porque el sistema de metricas esta caido— haria que el quiosco reintente
 * en bucle justo cuando la instalacion ya tiene un problema.
 */
final readonly class RedisKioskMetrics implements KioskMetrics
{
    /** Mismo prefijo que el resto de metricas, para que la tarea 3.1 las encuentre con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string LAST_SEEN = self::KEY_PREFIX.'kiosk_last_seen_seconds';

    public const string QUEUE_SIZE = self::KEY_PREFIX.'kiosk_offline_queue_size';

    public function __construct(private Redis $redis) {}

    public function heartbeat(string $deviceUuid, int $seenAtUnixSeconds, int $pendingQueueSize): void
    {
        try {
            $connection = $this->redis->connection();
            $label = 'device='.$deviceUuid;

            $connection->command('HSET', [self::LAST_SEEN, $label, $seenAtUnixSeconds]);
            $connection->command('HSET', [self::QUEUE_SIZE, $label, $pendingQueueSize]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
