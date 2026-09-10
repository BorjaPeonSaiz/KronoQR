<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Job\GenerateDataExportJob;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Support\Observability\Metrics\DatabaseQueryMetrics;
use App\Support\Observability\Metrics\QueueJobMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Observability\ProbeJob;

/*
 * Las tres series **transversales** del §8.2 cableadas de verdad:
 * `queue_job_duration_seconds{job}`, `queue_jobs_failed_total{job}` y
 * `db_query_duration_seconds{operation}` (decision 4 de la ficha 3.1).
 *
 * ## Por que `Queue::fake()` no vale aqui
 *
 * Porque `Queue::fake()` intercepta el despacho y **no emite `JobProcessing` ni
 * `JobProcessed`**, que son exactamente los dos eventos de los que cuelga esta
 * instrumentacion. Una prueba con la cola falseada afirmaria que el trabajo se
 * encolo —que no es lo que aqui se discute— y dejaria pasar el unico fallo
 * posible: que los oyentes no esten registrados. Se usa el conector `sync`, que
 * es el de la suite y el que emite los eventos de verdad.
 *
 * ## Y por que se lee Redis y no un doble
 *
 * `RedisMetricWriter` **se traga sus propios fallos** (regla dura 19: medir no
 * puede tumbar un trabajo de la cola, que puede ser el reenvio de un fichaje de
 * la cola offline). Contra un doble, «conto» y «lo intento y no pudo» son
 * indistinguibles. Aqui se mira el hash que `/metrics` publica.
 *
 * ## La invariante que se afirma en los histogramas
 *
 * `le="+Inf"` tiene que valer lo mismo que `count`. Es el contrato de un
 * histograma de Prometheus y lo que hace que `histogram_quantile()` devuelva un
 * numero en vez de `NaN`; sin ella el panel de la tarea 3.2 se queda en blanco
 * sin que nada falle.
 *
 * ## Requisitos
 *
 * El §8.2 tecnico no tiene identificador propio en `docs/requisitos.yaml`
 * (decision 12 de la ficha lo dice: el §9 del doc 01 se etiqueta con los que la
 * ficha indica). Se usa `RQ-06`, que es con el que ya estan etiquetadas las
 * pruebas hermanas del formato de exposicion —`MetricsEndpointTest` y
 * `MetricsExpositionTest`—, y `RS-09` en el caso que sale por `/metrics`.
 */

uses(RefreshDatabase::class);

/**
 * Las claves que este fichero toca. Redis **no** se limpia entre pruebas como
 * si hace la base de datos: sin esto, cada prueba heredaria los recuentos de la
 * anterior y las aserciones dependerian del orden de ejecucion.
 *
 * @return list<string>
 */
function seriesTransversales(): array
{
    return [
        MetricCatalogue::KEY_PREFIX.QueueJobMetrics::DURATION.':job=ProbeJob',
        MetricCatalogue::KEY_PREFIX.QueueJobMetrics::DURATION.':job=GenerateDataExportJob',
        MetricCatalogue::KEY_PREFIX.QueueJobMetrics::FAILED_TOTAL,
        MetricCatalogue::KEY_PREFIX.DatabaseQueryMetrics::DURATION.':operation=select',
    ];
}

beforeEach(function (): void {
    foreach (seriesTransversales() as $key) {
        app(Redis::class)->connection()->command('DEL', [$key]);
    }
});

afterEach(function (): void {
    foreach (seriesTransversales() as $key) {
        app(Redis::class)->connection()->command('DEL', [$key]);
    }
});

/**
 * @return array<string, string>
 */
function camposDeSerie(string $key): array
{
    /** @var array<string, string> $fields */
    $fields = app(Redis::class)->connection()->command('HGETALL', [$key]);

    return $fields;
}

it('mide la duracion de un trabajo procesado y la deja en la serie del catalogo', function (): void {
    ProbeJob::dispatch();

    $campos = camposDeSerie(MetricCatalogue::KEY_PREFIX.QueueJobMetrics::DURATION.':job=ProbeJob');

    expect($campos)->toHaveKey('count')
        ->and($campos['count'])->toBe('1')
        // La invariante del histograma: el cubo infinito cuenta TODAS las
        // observaciones, tambien las que ningun cubo finito contiene.
        ->and($campos['le=+Inf'] ?? null)->toBe($campos['count'])
        ->and((float) ($campos['sum'] ?? 0))->toBeGreaterThan(0.0);
})->group('RQ-06');

it('la etiqueta es la clase corta del trabajo, tambien en un trabajo del producto', function (): void {
    // La cardinalidad de esta serie es el numero de trabajos del producto, una
    // decena, y no crece con el trafico. Con el espacio de nombres completo la
    // leyenda de Grafana seria ilegible; con el identificador del trabajo habria
    // una serie por fichaje reenviado y un directorio de identificadores sin
    // control de acceso (regla dura 21).
    //
    // El trabajo se despacha con un identificador que no existe: atrapa el fallo
    // por dentro y lo escribe en el log, asi que termina y emite `JobProcessed`
    // sin necesidad de montar una exportacion entera.
    GenerateDataExportJob::dispatch(Str::uuid7()->toString());

    expect(camposDeSerie(MetricCatalogue::KEY_PREFIX.QueueJobMetrics::DURATION.':job=GenerateDataExportJob'))
        ->toHaveKey('count');
})->group('RQ-06');

it('un trabajo que agota sus intentos suma en el contador de fallos', function (): void {
    // `JobFailed` es «se acabaron los intentos», no «fallo una vez»: un fallo
    // transitorio que el segundo intento resuelve no es una averia, y contarlo
    // haria que la serie subiera cada vez que Redis parpadea.
    try {
        ProbeJob::dispatch(true);
    } catch (RuntimeException) {
        // El conector `sync` propaga la excepcion al que despacho, despues de
        // haber emitido `JobFailed`. Es el evento lo que se mide.
    }

    expect(camposDeSerie(MetricCatalogue::KEY_PREFIX.QueueJobMetrics::FAILED_TOTAL))
        ->toBe(['job=ProbeJob' => '1']);
})->group('RQ-06');

it('un trabajo que falla tambien deja su duracion medida', function (): void {
    // Lo que tarda en reventar es informacion: un trabajo que falla al segundo
    // es un error de programacion y uno que falla a los cinco minutos es un
    // tiempo de espera agotado contra algo que no responde.
    try {
        ProbeJob::dispatch(true);
    } catch (RuntimeException) {
        // Ver arriba.
    }

    $campos = camposDeSerie(MetricCatalogue::KEY_PREFIX.QueueJobMetrics::DURATION.':job=ProbeJob');

    expect($campos['count'] ?? null)->toBe('1')
        ->and($campos['le=+Inf'] ?? null)->toBe('1');
})->group('RQ-06');

it('una peticion HTTP deja la duracion de sus consultas repartida por operacion', function (): void {
    // `/api/v1/ready` comprueba PostgreSQL y Redis (Anexo B): hace consultas de
    // verdad y no depende de ningun dato sembrado.
    //
    // El volcado ocurre en `terminating`, DESPUES de que el cliente tenga su
    // respuesta: instrumentar no puede empeorar el numero que se instrumenta.
    // El cliente de pruebas ejecuta `terminate()`, que es lo que hace que esta
    // afirmacion sea posible.
    Api::guest()->get('/api/v1/ready');

    $campos = camposDeSerie(MetricCatalogue::KEY_PREFIX.DatabaseQueryMetrics::DURATION.':operation=select');

    expect($campos)->toHaveKey('count')
        ->and((int) $campos['count'])->toBeGreaterThan(0)
        ->and($campos['le=+Inf'] ?? null)->toBe($campos['count'])
        ->and((float) ($campos['sum'] ?? 0))->toBeGreaterThan(0.0);
})->group('RQ-06');

it('el acumulador se vacia en cada volcado y no arrastra a la peticion siguiente', function (): void {
    /*
     * **EL FALLO QUE ESTO CIERRA SI ALGUIEN QUITA EL VACIADO** (decision 4 de la
     * ficha 3.1).
     *
     * `DatabaseQueryMetrics` es un **singleton del contenedor** y acumula en
     * memoria: suma las consultas de la peticion y las vuelca una sola vez al
     * terminar, para no doblar los viajes a Redis del camino de fichaje. El
     * precio de esa decision es que el acumulador tiene que vaciarse en el
     * volcado, y en PHP-FPM eso no se nota —el proceso muere despues— pero en un
     * *worker* de Horizon o bajo Octane el mismo objeto vive horas: sin vaciar,
     * cada peticion volveria a publicar todo lo de las anteriores y las cifras
     * crecerian de forma cuadratica.
     *
     * Se llama a `flush()` dos veces seguidas: el segundo volcado no puede
     * escribir nada, porque no hay nada acumulado.
     */
    $clave = MetricCatalogue::KEY_PREFIX.DatabaseQueryMetrics::DURATION.':operation=select';

    Api::guest()->get('/api/v1/ready');

    $despuesDeLaPeticion = (int) (camposDeSerie($clave)['count'] ?? 0);

    expect($despuesDeLaPeticion)->toBeGreaterThan(0);

    // El mismo objeto que acumulo durante la peticion: es un singleton.
    app(DatabaseQueryMetrics::class)->flush();
    app(DatabaseQueryMetrics::class)->flush();

    expect((int) (camposDeSerie($clave)['count'] ?? 0))->toBe($despuesDeLaPeticion);
})->group('RQ-06');

it('publica las tres series por /metrics con el formato de exposicion', function (): void {
    config(['observability.metrics.allow_cidr' => '10.91.0.0/24']);

    ProbeJob::dispatch();

    try {
        ProbeJob::dispatch(true);
    } catch (RuntimeException) {
        // Ver arriba.
    }

    // Antes del scrape, y no en la misma peticion: `db_query_duration_seconds`
    // se vuelca en `terminating`, asi que las consultas del propio `/metrics`
    // llegan a Redis DESPUES de haberse compuesto la respuesta. Es lo correcto
    // —medir no puede encarecer lo medido— y obliga a que haya habido trafico
    // antes, que es justo lo que ocurre en una instalacion real.
    Api::guest()->get('/api/v1/ready');

    $cuerpo = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    expect($cuerpo)
        ->toContain('# TYPE queue_job_duration_seconds histogram')
        ->toContain('queue_job_duration_seconds_bucket{job="ProbeJob",le="+Inf"} 2')
        ->toContain('queue_job_duration_seconds_count{job="ProbeJob"} 2')
        ->toContain('# TYPE queue_jobs_failed_total counter')
        ->toContain('queue_jobs_failed_total{job="ProbeJob"} 1')
        ->toContain('# TYPE db_query_duration_seconds histogram')
        ->toMatch('/^db_query_duration_seconds_bucket\{operation="select",le="\+Inf"\} \d+$/m');
})->group('RQ-06', 'RS-09');
