<?php

declare(strict_types=1);

use App\Http\Controllers\MetricsController;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use Illuminate\Contracts\Redis\Factory as Redis;
use Tests\Support\Health\UnavailableRedis;
use Tests\Support\Http\Api;

/*
 * `GET /metrics` — el formato de exposicion de Prometheus, restringido a la red
 * interna (doc 02 §8.1 y §8.2, doc 01 Anexo B, RS-09, tarea 3.1).
 *
 * ## Por que Feature y no Contrato
 *
 * Esta ruta **no esta en `openapi.yaml`, a proposito** (decision 3 de la ficha):
 * va fuera de `/api/v1`, no la consume ninguna de las tres SPA ni el quiosco, y
 * su forma la fija Prometheus. Meterla en el contrato del que se genera el
 * cliente TypeScript habria descrito un `text/plain` que nadie va a pedir. Lo
 * que hace las veces de contrato aqui es el formato de exposicion, y es lo que
 * se afirma: `# HELP`, `# TYPE` y muestras con sus etiquetas entre llaves.
 *
 * ## La autorizacion negativa que exige el §9.5 es de RED, no de rol
 *
 * Ningun token y ningun rol abren `/metrics`: quien la lee es Prometheus, que no
 * tiene cuenta. La comprobacion equivalente —y la que este fichero hace— es que
 * desde fuera de `METRICS_ALLOW_CIDR` la respuesta es `403`. La misma
 * restriccion la aplica Nginx en el borde; esto es la segunda guarda, para que
 * una plantilla mal editada no deje las series a la vista.
 *
 * ## Sin base de datos
 *
 * Este fichero no usa `RefreshDatabase`: el endpoint no toca PostgreSQL. Si
 * algun dia lo necesitara, seria una averia — un scrape cada quince segundos que
 * consulta la base es carga permanente sobre el camino del fichaje.
 */

beforeEach(function (): void {
    // Un rango de laboratorio, nunca el de la instalacion: lo que se prueba es
    // el mecanismo, y una prueba que dependiera del `.env` de quien la ejecuta
    // pasaria o fallaria segun la maquina.
    config(['observability.metrics.allow_cidr' => '10.91.0.0/24, 2001:db8::/32']);
});

it('responde 200 en texto plano desde una IP de la red autorizada', function (): void {
    $response = Api::guest()->fromIp('10.91.0.5')->get('/metrics');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))
        // La version del formato es obligatoria: sin ella Prometheus asume
        // 0.0.4 igualmente, pero un colector estricto —o un futuro cambio de
        // version— dejaria de leer el objetivo sin avisar.
        ->toBe(MetricsController::CONTENT_TYPE)
        ->and($response->headers->get('Cache-Control'))
        // Un scrape es una foto del instante. Una respuesta cacheada haria que
        // un contador pareciera plano justo cuando esta subiendo.
        ->toContain('no-store');
})->group('RS-09');

it('responde 403 desde fuera de la red autorizada', function (): void {
    // La autorizacion negativa del §9.5 para esta ruta (regla dura 18 aplicada a
    // una puerta que no es de identidad sino de red).
    Api::guest()->fromIp('203.0.113.7')->get('/metrics')->assertForbidden();
})->group('RS-09');

it('responde 403 desde el bucle local cuando no es la red autorizada', function (): void {
    // El caso que mas facil es dejar abierto sin querer: la maquina que publica
    // los puertos NO es la red de sondeo, y Nginx tambien la rechaza.
    Api::guest()->fromIp('127.0.0.1')->get('/metrics')->assertForbidden();
})->group('RS-09');

it('acepta IPv6 dentro del rango configurado', function (): void {
    Api::guest()->fromIp('2001:db8::1')->get('/metrics')->assertOk();
})->group('RS-09');

it('no filtra nada en el cuerpo del rechazo', function (): void {
    // Ni motivo, ni rango configurado, ni `problem+json`: la respuesta tiene que
    // ser indistinguible de la que da Nginx cuando el bloqueo ocurre en el
    // borde. Un cuerpo explicativo le diria a quien busca el endpoint que ha
    // dado con el.
    $response = Api::guest()->fromIp('203.0.113.7')->get('/metrics');

    expect($response->getContent())->toBe('');
})->group('RS-09');

it('publica las series del catalogo con el formato de exposicion', function (): void {
    // Una peticion cualquiera deja su marca en `http_requests_total` y en
    // `http_request_duration_seconds` (RecordHttpMetrics mide en `terminate()`,
    // que este cliente ejecuta).
    Api::guest()->get('/api/v1/health')->assertOk();

    $body = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    expect($body)
        ->toContain('# TYPE http_requests_total counter')
        ->toContain('# HELP http_requests_total ')
        ->toMatch('/^http_requests_total\{route="[^"]*",method="[A-Z]+",status="\d+"\} \d+$/m')
        // El histograma, entero: sin `+Inf` y sin `_count`,
        // `histogram_quantile()` devuelve NaN y el panel se queda en blanco.
        ->toContain('# TYPE http_request_duration_seconds histogram')
        ->toMatch('/^http_request_duration_seconds_bucket\{.*le="\+Inf"\} \d+$/m')
        ->toMatch('/^http_request_duration_seconds_count\{/m')
        ->toMatch('/^http_request_duration_seconds_sum\{/m');
})->group('RS-09', 'RQ-06');

it('calcula la profundidad de las colas en el momento del scrape', function (): void {
    // `queue_jobs_pending` no la escribe nadie en Redis: es un estado que se
    // pregunta a la cola cuando Prometheus llama.
    $body = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    expect($body)
        ->toContain('# TYPE queue_jobs_pending gauge')
        ->toMatch('/^queue_jobs_pending\{queue="[^"]+"\} \d+$/m');
})->group('RQ-06');

it('no publica ninguna serie que el catalogo no declare', function (): void {
    $body = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    preg_match_all('/^# TYPE ([a-z_][a-z0-9_]*) /m', $body, $matches);

    $unexpected = array_values(array_diff($matches[1], MetricCatalogue::names()));

    // Regla dura 21: lo que sale por aqui viaja al fabricante en el paquete de
    // diagnostico. Una serie que aparece sin estar catalogada es una que nadie
    // ha revisado para saber si sus etiquetas identifican a alguien.
    expect($unexpected)->toBe([]);
})->group('RS-09', 'RL-08');

it('sigue respondiendo 200 cuando Redis no contesta', function (): void {
    // La decision de la ficha: **nunca 5xx**. Prometheus marca el objetivo como
    // DOWN ante un 5xx y la alerta que salta dice «la aplicacion no responde»;
    // una averia del almacen de metricas se leeria como una caida del producto y
    // alguien iria a reiniciar un sistema que esta atendiendo fichajes.
    app()->instance(Redis::class, new UnavailableRedis);
    app()->forgetInstance(RedisMetricReader::class);

    $response = Api::guest()->fromIp('10.91.0.5')->get('/metrics');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(MetricsController::CONTENT_TYPE);
})->group('RS-09', 'RQ-06');
