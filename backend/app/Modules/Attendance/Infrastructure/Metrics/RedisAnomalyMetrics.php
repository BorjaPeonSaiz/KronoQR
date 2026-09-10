<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\AnomalyMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `anomalous_patterns_detected_total{pattern}` sobre Redis (doc 02 §8.2).
 *
 * ## Redis y no un fichero `.prom`, al contrario que `TextfileProjectionMetrics`
 *
 * Las dos las escribe un comando programado que corre de madrugada y termina,
 * asi que el argumento del colector *textfile* —«un contador en memoria de un
 * proceso que termina no lo lee nadie»— vale para las dos. La diferencia es
 * **que tipo de numero es cada una**: `projection_divergence_total` se
 * reescribe entero cada noche leyendo su valor anterior del propio fichero,
 * mientras que esto es un contador puro que solo suma. `HINCRBY` es atomico,
 * sobrevive al reinicio del proceso y no necesita releer nada; publicarlo por
 * fichero obligaria a parsear el `.prom` de ayer para no perder la cuenta.
 *
 * ## Un `HINCRBY` por tipo, no cuarenta por hallazgo
 *
 * La pasada entrega el recuento ya agrupado, asi que el numero de comandos es
 * el numero de tipos de anomalia —media docena—, no el de hallazgos. Una noche
 * con cuarenta turnos abiertos cuesta un comando, no cuarenta.
 *
 * ## Medir no puede romper la revision
 *
 * Se llega aqui con las incidencias ya escritas y sus asientos de auditoria
 * cerrados. Convertir eso en un fallo del comando por no poder incrementar un
 * contador haria que el planificador lo marcara en rojo y que alguien fuera a
 * buscar una averia que no existe.
 */
final readonly class RedisAnomalyMetrics implements AnomalyMetrics
{
    public const string ANOMALIES_TOTAL = RedisScanMetrics::KEY_PREFIX.'anomalous_patterns_detected_total';

    public function __construct(private Redis $redis) {}

    public function anomaliesDetected(array $byPattern): void
    {
        if ($byPattern === []) {
            return;
        }

        try {
            $connection = $this->redis->connection();

            foreach ($byPattern as $pattern => $count) {
                if ($count < 1) {
                    continue;
                }

                $connection->command('HINCRBY', [self::ANOMALIES_TOTAL, 'pattern='.$pattern, $count]);
            }
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
