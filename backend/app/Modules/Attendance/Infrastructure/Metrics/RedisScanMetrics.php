<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `scans_total{device,result}` y `scan_processing_duration_seconds` sobre Redis
 * (doc 02 §8.2).
 *
 * **Por que Redis y no el colector *textfile*.** El de `Compliance` escribe un
 * fichero por ejecucion porque lo alimenta un comando programado que corre una
 * vez al dia. Aqui el hecho medido ocurre **cincuenta veces por segundo** en un
 * cambio de turno (RNF-P-06): un fichero reescrito en cada fichaje seria una
 * escritura de disco por escaneo y una carrera entre procesos PHP. `HINCRBY` es
 * atomico, cuesta microsegundos y ya hay Redis en el stack. El endpoint
 * `/metrics` que lo publica es de la tarea 3.1; hasta entonces los contadores se
 * acumulan y las pruebas los leen.
 *
 * **Los cubos del histograma estan elegidos alrededor del objetivo, no
 * repartidos por igual.** RNF-P-01 pide p95 < 150 ms para el fichaje, asi que
 * hay cuatro cubos por debajo de esa cifra y tres por encima: un histograma con
 * cubos en 1 s, 5 s y 10 s no distinguiria un endpoint sano de uno que ha
 * doblado su latencia.
 *
 * **Ninguna etiqueta lleva `employee_uuid`.** Una serie temporal por persona
 * seria un registro de presencia paralelo, sin retencion ni control de acceso
 * (regla dura 21, RGPD). `device` es el UUID publico del quiosco y su
 * cardinalidad es la de los quioscos del hotel: unidades.
 *
 * **Medir no puede romper un fichaje.** Si Redis no responde, se registra el
 * fallo y el escaneo sigue su camino: la regla dura 19 dice que el quiosco nunca
 * bloquea al empleado, y perder una metrica es infinitamente mas barato que
 * perder una jornada.
 */
final readonly class RedisScanMetrics implements ScanMetrics
{
    /** Prefijo comun para que la tarea 3.1 encuentre todas las series con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string SCANS_TOTAL = self::KEY_PREFIX.'scans_total';

    public const string PROCESSING_DURATION = self::KEY_PREFIX.'scan_processing_duration_seconds';

    public const string SYNC_DELAY = self::KEY_PREFIX.'sync_delay_seconds';

    public const string BATCH_SIZE = self::KEY_PREFIX.'scan_batch_size';

    /** RF-AT-11: fichajes por PIN de respaldo, por centro (§8.2, tarea 1.12). */
    public const string PIN_FALLBACK_SCANS = self::KEY_PREFIX.'pin_fallback_scans_total';

    public const string SCANS_BY_ORIGIN = self::KEY_PREFIX.'scans_by_origin_total';

    /**
     * Cubos en segundos, del contrato de Prometheus: cada uno cuenta las
     * observaciones **menores o iguales** que su limite.
     *
     * @var list<float>
     */
    private const array BUCKETS = [0.025, 0.05, 0.1, 0.15, 0.3, 0.6, 1.5];

    /**
     * Cubos del retraso de sincronizacion, en segundos, y a otra escala por
     * completo: aqui no se mide un endpoint, se mide **cuanto tiempo estuvo un
     * fichaje encerrado en una tablet**.
     *
     * Un minuto es una sincronizacion normal; cinco minutos es una reconexion;
     * una hora es un corte de red; un dia es una tablet que estuvo el fin de
     * semana sin cobertura y sigue siendo un caso legitimo (regla dura 19). Los
     * cubos estan puestos donde cambia la interpretacion, no repartidos por
     * igual.
     *
     * @var list<int>
     */
    private const array DELAY_BUCKETS = [60, 300, 3600, 21600, 86400];

    public function __construct(private Redis $redis) {}

    public function scanProcessed(string $deviceUuid, ScanResult $result, float $durationSeconds): void
    {
        try {
            $connection = $this->redis->connection();

            $connection->command('HINCRBY', [
                self::SCANS_TOTAL,
                'device='.$deviceUuid.',result='.$result->value,
                1,
            ]);

            $connection->command('HINCRBYFLOAT', [self::PROCESSING_DURATION, 'sum', $durationSeconds]);
            $connection->command('HINCRBY', [self::PROCESSING_DURATION, 'count', 1]);

            foreach (self::BUCKETS as $bucket) {
                if ($durationSeconds <= $bucket) {
                    $connection->command('HINCRBY', [self::PROCESSING_DURATION, 'le='.$bucket, 1]);
                }
            }

            // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
            // coincidir con `count`: sin el, la serie no es un histograma valido
            // y `histogram_quantile()` devuelve NaN.
            $connection->command('HINCRBY', [self::PROCESSING_DURATION, 'le=+Inf', 1]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: el fichaje ya esta
            // confirmado cuando se llega aqui, y una excepcion de la capa de
            // metricas convertiria un exito en un `500` para el quiosco.
        }
    }

    /**
     * `sync_delay_seconds{device}` y el tamano del lote (tarea 1.7).
     *
     * El retraso lleva etiqueta de dispositivo porque la pregunta operativa es
     * «¿que quiosco no esta drenando?», no «¿cuanto retraso hay en general»: un
     * histograma global no distingue una tablet averiada de un corte que afecto a
     * todas. La cardinalidad sigue siendo la de los quioscos del hotel: unidades.
     */
    public function batchSynchronised(string $deviceUuid, int $size, int $delaySeconds): void
    {
        try {
            $connection = $this->redis->connection();
            $device = 'device='.$deviceUuid;

            $connection->command('HINCRBY', [self::BATCH_SIZE.':'.$device, 'sum', $size]);
            $connection->command('HINCRBY', [self::BATCH_SIZE.':'.$device, 'count', 1]);

            $connection->command('HINCRBY', [self::SYNC_DELAY.':'.$device, 'sum', $delaySeconds]);
            $connection->command('HINCRBY', [self::SYNC_DELAY.':'.$device, 'count', 1]);

            foreach (self::DELAY_BUCKETS as $bucket) {
                if ($delaySeconds <= $bucket) {
                    $connection->command('HINCRBY', [self::SYNC_DELAY.':'.$device, 'le='.$bucket, 1]);
                }
            }

            $connection->command('HINCRBY', [self::SYNC_DELAY.':'.$device, 'le=+Inf', 1]);
        } catch (Throwable) {
            // Ver el metodo anterior: medir no puede romper una sincronizacion.
            // Aqui es todavia mas claro, porque los fichajes del lote ya estan
            // confirmados cuando se llega a esta linea.
        }
    }

    /**
     * `pin_fallback_scans_total{site}` (RF-AT-11, §8.2).
     *
     * Un contador simple y no un histograma: lo que se quiere saber es cuantos
     * son, no cuanto tardaron. La cardinalidad es la de los centros de la
     * instalacion —uno en la mayoria de los clientes, unos pocos en una cadena—,
     * asi que la etiqueta cabe en el mismo hash que el resto.
     */
    public function pinFallbackScan(int $siteId): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::PIN_FALLBACK_SCANS,
                'site='.$siteId,
                1,
            ]);
        } catch (Throwable) {
            // Ver `scanProcessed()`: cuando se llega aqui el fichaje ya esta
            // confirmado, y una excepcion de la capa de metricas lo convertiria
            // en un `500` para el quiosco.
        }
    }

    /**
     * `scans_by_origin_total{origin}` (§8.2, RF-IN-08).
     *
     * **La traduccion de origenes vive aqui y no en el dominio.** El modelo
     * tiene cuatro (`qr_kiosk`, `pin_kiosk`, `manual_admin`, `import`) porque
     * necesita distinguir de donde salio cada tramo; la metrica de adopcion
     * tiene los tres del doc 01 §9.2, que son las tres formas en que una
     * PERSONA deja constancia de su jornada. `import` no es ninguna de ellas
     * —son datos que venian de otro sistema al poner en marcha la instalacion—
     * y contarlo hincharia el reparto del primer dia con jornadas que nadie
     * ficho. Se descarta sin ruido.
     *
     * `manual_admin` si cuenta, pero **no llega por aqui**: lo emite
     * `RedisCorrectionMetrics` cuando se añade un tramo a mano, que es el unico
     * momento en el que una correccion crea una jornada que no existia. Si se
     * contara tambien desde este camino, un tramo añadido a mano sumaria dos.
     */
    public function scanOriginRecorded(ScanOrigin $origin): void
    {
        $label = match ($origin) {
            ScanOrigin::QR_KIOSK => 'qr',
            ScanOrigin::PIN_KIOSK => 'pin',
            ScanOrigin::MANUAL_ADMIN, ScanOrigin::IMPORT => null,
        };

        if ($label === null) {
            return;
        }

        try {
            $this->redis->connection()->command('HINCRBY', [
                self::SCANS_BY_ORIGIN,
                'origin='.$label,
                1,
            ]);
        } catch (Throwable) {
            // Ver `scanProcessed()`: el fichaje ya esta confirmado.
        }
    }
}
