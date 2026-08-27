<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `http_requests_total{route,method,status}` y
 * `http_request_duration_seconds{route,method,status}` (doc 02 §8.2).
 *
 * ## La etiqueta es el NOMBRE de la ruta, nunca su URI
 *
 * Es la decision que decide si esta metrica sirve o hunde a Prometheus. Con la
 * URI, `/api/v1/employees/{uuid}` produce **una serie temporal por empleado** —y
 * `/api/v1/scan` una por nada, pero las de plantilla son ademas un directorio de
 * UUIDs escrito en un sistema sin control de acceso ni retencion (regla dura 21,
 * RGPD)—. Con el nombre de la ruta, la cardinalidad es el numero de endpoints del
 * producto: decenas.
 *
 * Una peticion que no casa con ninguna ruta se agrupa como `unmatched`. Es lo que
 * impide que un escaneo de vulnerabilidades —que prueba miles de rutas
 * inventadas— genere miles de series.
 *
 * ## Por que Redis
 *
 * Mismo motivo que `RedisScanMetrics`: el proceso PHP que atiende una peticion
 * termina con ella, asi que un contador en memoria no lo lee nadie. `HINCRBY` es
 * atomico entre procesos y cuesta microsegundos. El endpoint `/metrics` que lo
 * publica es de la tarea 3.1; hasta entonces los contadores se acumulan.
 *
 * ## Los cubos son los del presupuesto de latencia
 *
 * RNF-P-02 fija p95 < 150 ms y p99 < 400 ms, asi que hay cubos a un lado y a otro
 * de esas dos cifras. Un histograma con cubos en 1 s, 5 s y 10 s no distingue un
 * endpoint sano de uno que ha doblado su latencia, que es exactamente lo que hay
 * que poder ver.
 *
 * ## Medir no puede romper una peticion
 *
 * Todo va envuelto: si Redis no responde, la respuesta sale igual. La regla dura
 * 19 es tajante —el quiosco nunca bloquea al empleado— y este middleware corre en
 * el camino de fichaje.
 *
 * **Se mide en `terminate()` y no alrededor de `$next()`**: asi el coste de
 * escribir en Redis cae despues de que el cliente tenga su respuesta, que es lo
 * que hace que instrumentar no empeore el propio numero que se instrumenta.
 */
final readonly class RecordHttpMetrics
{
    /** Mismo prefijo que el resto de metricas, para que la tarea 3.1 las encuentre con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string REQUESTS_TOTAL = self::KEY_PREFIX.'http_requests_total';

    public const string REQUEST_DURATION = self::KEY_PREFIX.'http_request_duration_seconds';

    /** Ruta sin nombre o peticion que no casa con ninguna: una sola serie para todas. */
    public const string UNMATCHED_ROUTE = 'unmatched';

    /**
     * Cubos en segundos, alrededor del presupuesto de RNF-P-02.
     *
     * @var list<float>
     */
    private const array BUCKETS = [0.025, 0.05, 0.1, 0.15, 0.3, 0.4, 1.0, 3.0];

    public function __construct(private Redis $redis) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Se guarda en la propia peticion y no en una propiedad: el middleware es
        // singleton y dos peticiones concurrentes en un worker de larga vida
        // (Octane, colas) compartirian la marca de inicio.
        $request->attributes->set('kronoqr.started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $startedAt = $request->attributes->get('kronoqr.started_at');

            if (! is_float($startedAt)) {
                return;
            }

            $this->record(
                $this->routeNameOf($request),
                $request->getMethod(),
                $response->getStatusCode(),
                microtime(true) - $startedAt,
            );
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    private function record(string $route, string $method, int $status, float $seconds): void
    {
        $connection = $this->redis->connection();
        $labels = 'route='.$route.',method='.$method.',status='.$status;

        $connection->command('HINCRBY', [self::REQUESTS_TOTAL, $labels, 1]);

        $key = self::REQUEST_DURATION.':'.$labels;

        $connection->command('HINCRBYFLOAT', [$key, 'sum', $seconds]);
        $connection->command('HINCRBY', [$key, 'count', 1]);

        foreach (self::BUCKETS as $bucket) {
            if ($seconds <= $bucket) {
                $connection->command('HINCRBY', [$key, 'le='.$bucket, 1]);
            }
        }

        // `+Inf` es obligatorio en un histograma de Prometheus y tiene que
        // coincidir con `count`: sin el, `histogram_quantile()` devuelve NaN.
        $connection->command('HINCRBY', [$key, 'le=+Inf', 1]);
    }

    private function routeNameOf(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $name = $route->getName();

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        return self::UNMATCHED_ROUTE;
    }
}
