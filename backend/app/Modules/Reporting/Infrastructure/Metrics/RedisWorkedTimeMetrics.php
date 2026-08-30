<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\WorkedTimeMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `worked_minutes_total{site,department}` sobre Redis (doc 02 §8.2).
 *
 * **Por que Redis y no el colector *textfile*.** Las metricas de presencia y de
 * auditoria escriben un fichero por ejecucion porque las alimenta un comando
 * programado que corre una vez al dia. Aqui el hecho medido ocurre en cada
 * cambio de turno, decenas de veces por minuto (RNF-P-06): un fichero reescrito
 * en cada salida seria una escritura de disco por fichaje y una carrera entre
 * procesos PHP. `HINCRBY` es atomico, cuesta microsegundos y ya hay Redis en el
 * stack. Es el mismo criterio —y el mismo prefijo de clave— que
 * `Attendance\Infrastructure\Metrics\RedisScanMetrics`, nombrada asi y no como
 * `{@see}` porque un `use` de otro modulo es la frontera del §1.6 que Deptrac
 * rechaza.
 *
 * El endpoint `/metrics` que lo publica es de la tarea 3.1; hasta entonces los
 * contadores se acumulan y las pruebas los leen.
 *
 * **Medir no puede romper un fichaje.** Si Redis no responde, el escaneo sigue su
 * camino: la regla dura 19 dice que el quiosco nunca bloquea al empleado, y
 * perder una metrica es infinitamente mas barato que perder una jornada. Aqui es
 * todavia mas claro que en las hermanas, porque cuando se llega a esta linea el
 * tramo ya esta cerrado y confirmado.
 *
 * **Ninguna etiqueta lleva `employee_uuid`** (regla dura 21). `site` es la clave
 * interna del centro —la misma que ya usan `pin_fallback_scans_total` y el gauge
 * de turnos abiertos— y `department` es el nombre, que es lo que se lee en un
 * panel de Grafana. Un departamento sin nombre —quien no tiene ninguno— cae en la
 * etiqueta vacia, que tiene que existir para que la suma cuadre con el centro.
 */
final readonly class RedisWorkedTimeMetrics implements WorkedTimeMetrics
{
    /** El mismo prefijo que el resto de las series, para que la tarea 3.1 las encuentre con un `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string WORKED_MINUTES_TOTAL = self::KEY_PREFIX.'worked_minutes_total';

    public function __construct(private Redis $redis) {}

    public function workedMinutes(int $siteId, string $department, int $minutes): void
    {
        if ($minutes <= 0) {
            // Un tramo de cero minutos no aporta nada al contador, y uno
            // negativo no existe: `HINCRBY` con un negativo haria decrecer una
            // serie que Prometheus interpretaria como un reinicio del proceso.
            return;
        }

        try {
            $this->redis->connection()->command('HINCRBY', [
                self::WORKED_MINUTES_TOTAL,
                'site='.$siteId.',department='.$department,
                $minutes,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: el tramo ya esta
            // cerrado cuando se llega aqui, y una excepcion de la capa de
            // metricas convertiria un fichaje correcto en un `500`.
        }
    }
}
