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

    /**
     * `kiosk_pairing_total{result,reason}` (RF-PD-06, doc 02 §8.2).
     *
     * Un solo contador con etiquetas y no cuatro metricas: las cuatro responden
     * a la misma pregunta —«que esta pasando con los emparejamientos»— y
     * separadas obligarian a sumarlas para saber cuantos intentos hubo.
     */
    public const string PAIRING_TOTAL = self::KEY_PREFIX.'kiosk_pairing_total';

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

    public function pairingRequested(): void
    {
        $this->increment(self::PAIRING_TOTAL, 'result=requested');
    }

    public function pairingConfirmed(): void
    {
        $this->increment(self::PAIRING_TOTAL, 'result=confirmed');
    }

    public function pairingClaimed(): void
    {
        $this->increment(self::PAIRING_TOTAL, 'result=claimed');
    }

    public function pairingRejected(string $reason): void
    {
        $this->increment(self::PAIRING_TOTAL, 'result=rejected,reason='.$reason);
    }

    /**
     * `HINCRBY` y no `HSET`: **estos si son contadores**, al contrario que los
     * dos gauges del latido. Lo que interesa de un emparejamiento es cuantos ha
     * habido y en que proporcion se rechazan, no cual fue el ultimo.
     *
     * Medir tampoco puede romper aqui: si Redis no responde, el emparejamiento
     * sigue su camino. Un `500` porque el sistema de metricas esta caido dejaria
     * una tablet sin poder darse de alta.
     */
    private function increment(string $key, string $label): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [$key, $label, 1]);
        } catch (Throwable) {
            // Silencio deliberado y acotado: ver el docblock.
        }
    }
}
