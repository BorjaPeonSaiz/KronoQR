<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `incident_resolution_seconds{type}` sobre Redis (doc 02 §8.2).
 *
 * ## Por que Redis y no el colector *textfile* como el resto de este modulo
 *
 * `TextfileIncidentMetrics` publica un **gauge** que se recalcula entero desde la
 * tabla en una tarea programada, y por eso le vale un fichero por ejecucion. Esto
 * es un **histograma**: se observa en el momento en que una persona cierra una
 * incidencia, dentro de una peticion HTTP, y no se puede reconstruir despues
 * —la distribucion de lo que tardo cada una no esta en ninguna columna—. Un
 * fichero reescrito en cada resolucion seria una escritura de disco por peticion
 * y una carrera entre procesos PHP; `HINCRBY` es atomico y ya hay Redis en el
 * stack. Es el mismo criterio que separa `RedisCorrectionMetrics` de las
 * metricas *textfile* de auditoria.
 *
 * ## El prefijo se repite y no se importa
 *
 * `kronoqr:metrics:` esta escrito aqui igual que en las otras cinco clases que
 * publican por Redis. Importar la constante de `Attendance` seria una arista
 * entre modulos que el §1.6 no concede, y un prefijo distinto dejaria la serie
 * fuera del `SCAN` con el que el endpoint `/metrics` de la tarea 3.1 las recoge
 * todas. Lo ata una prueba, no la buena fe.
 *
 * ## Los cubos estan puestos donde cambia la interpretacion
 *
 * El objetivo del doc 01 §1.3 es **menos de 24 h desde la deteccion hasta la
 * resolucion**, asi que hay tres cubos por debajo de esa cifra y dos por encima:
 * una hora es «lo miro quien abrio la bandeja por la mañana», cuatro es «el mismo
 * turno», doce es «el mismo dia», veinticuatro es el objetivo, y a partir de ahi
 * lo que interesa es distinguir «se le paso un dia» de «lleva una semana».
 * Cubos repartidos por igual no distinguirian un hotel que trabaja su bandeja de
 * uno que la vacia el viernes de golpe.
 *
 * ## Medir no puede romper una resolucion
 *
 * Cuando se llega aqui la incidencia ya esta cerrada, su nota escrita y su
 * asiento de `audit_log` confirmado. Convertir eso en un `500` por no poder
 * incrementar un contador le diria a quien resolvio que no se guardo nada, y
 * volveria a intentarlo — recibiendo entonces el `409` de una resolucion que si
 * existe.
 */
final readonly class RedisIncidentResolutionMetrics implements IncidentResolutionMetrics
{
    /** Prefijo comun para que el endpoint `/metrics` encuentre todas las series con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string RESOLUTION_SECONDS = self::KEY_PREFIX.'incident_resolution_seconds';

    /**
     * Cubos en segundos: 1 h, 4 h, 12 h, 24 h, 48 h y 7 dias. Cada uno cuenta las
     * observaciones **menores o iguales** que su limite.
     *
     * @var list<int>
     */
    private const array BUCKETS = [3600, 14400, 43200, 86400, 172800, 604800];

    public function __construct(private Redis $redis) {}

    public function resolutionObserved(IncidentType $type, int $seconds): void
    {
        try {
            $connection = $this->redis->connection();
            $key = self::RESOLUTION_SECONDS.':type='.$type->value;

            $connection->command('HINCRBY', [$key, 'sum', $seconds]);
            $connection->command('HINCRBY', [$key, 'count', 1]);

            foreach (self::BUCKETS as $bucket) {
                if ($seconds <= $bucket) {
                    $connection->command('HINCRBY', [$key, 'le='.$bucket, 1]);
                }
            }

            // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
            // coincidir con `count`: sin el, la serie no es un histograma valido
            // y `histogram_quantile()` devuelve NaN. Aqui ademas es el cubo que
            // de verdad se mira: una incidencia que lleva mas de una semana sin
            // trabajarse solo aparece ahi.
            $connection->command('HINCRBY', [$key, 'le=+Inf', 1]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
