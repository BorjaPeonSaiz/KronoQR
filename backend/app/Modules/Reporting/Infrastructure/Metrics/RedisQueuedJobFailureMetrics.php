<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\QueuedJobFailureMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `queue_jobs_failed_total{job}` sobre Redis, para el trabajo que captura su
 * excepcion (doc 02 §8.2).
 *
 * **La misma clave y la misma etiqueta que `QueueJobMetrics`**, nombrada asi y
 * no como `{@see}` porque un `use` de `App\Support` desde un modulo es la
 * frontera que Deptrac rechaza: `kronoqr:metrics:queue_jobs_failed_total` con el
 * campo `job=<clase corta>`, y `HINCRBY`, que es atomico. El endpoint `/metrics`
 * de la tarea 3.1 ya recorre ese prefijo, asi que no hay nada que registrar en
 * el catalogo: es una serie que ya existe, escrita desde un segundo sitio.
 *
 * **Medir no puede romper nada.** Si Redis no responde, el informe sigue
 * marcado como fallido y el log tecnico sigue teniendo la clase de la excepcion;
 * perder un punto de una serie es infinitamente mas barato que sustituir la
 * causa original de un fallo por otra.
 */
final readonly class RedisQueuedJobFailureMetrics implements QueuedJobFailureMetrics
{
    /** El mismo prefijo que el resto de las series del producto. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string QUEUE_JOBS_FAILED_TOTAL = self::KEY_PREFIX.'queue_jobs_failed_total';

    public function __construct(private Redis $redis) {}

    public function failed(string $job): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::QUEUE_JOBS_FAILED_TOTAL,
                'job='.$job,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo. Ver el docblock.
        }
    }
}
