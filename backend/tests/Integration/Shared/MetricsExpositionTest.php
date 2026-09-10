<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Infrastructure\Metrics\RedisAnomalyMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\RedisCorrectionMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\RedisScanMetrics;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use Illuminate\Contracts\Redis\Factory as Redis;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Tests\Support\Health\UnavailableRedis;

/*
 * Las series nuevas de la tarea 3.1 sobre Redis de verdad, y el lector que las
 * vuelve a componer (doc 02 §8.2, RF-IN-08, RF-PR-06).
 *
 * **Por que Integration y no un doble.** Lo mismo que en
 * `AuthenticationMetricsTest`: lo que se comprueba no es que un adaptador llame
 * a un metodo, es **el nombre exacto de la serie, la forma exacta de sus
 * etiquetas y que el lector las encuentra**. Un doble daria por bueno cualquier
 * nombre — y el fallo que esta tarea vino a arreglar fue precisamente ese: doce
 * series escribiendose en Redis que nadie exponia, con todas sus pruebas en
 * verde.
 *
 * Y hay una trampa que solo aparece contra el cliente real: `phpredis` **no
 * prefija el patron `MATCH` de un `SCAN`** con el prefijo global de la
 * instalacion, ni se lo quita a las claves que devuelve, y ademas trata un
 * cursor inicial `0` como «el recorrido ya termino». Con cualquiera de los dos
 * detalles mal, las cinco series que viven como claves sueltas desaparecen de
 * `/metrics` sin un solo error en el log.
 *
 * **Sin base de datos**: ninguna de estas series pasa por PostgreSQL.
 */

/**
 * Las claves que esta prueba toca, para dejarlas como estaban.
 *
 * @return list<string>
 */
function seriesUnderTest(): array
{
    return [
        RedisScanMetrics::SCANS_BY_ORIGIN,
        RedisAnomalyMetrics::ANOMALIES_TOTAL,
    ];
}

beforeEach(function (): void {
    foreach (seriesUnderTest() as $key) {
        app(Redis::class)->connection()->command('DEL', [$key]);
    }
});

afterEach(function (): void {
    foreach (seriesUnderTest() as $key) {
        app(Redis::class)->connection()->command('DEL', [$key]);
    }
});

/**
 * @return array<string, MetricFamilySamples>
 */
function familiesByName(): array
{
    $families = [];

    foreach (app(RedisMetricReader::class)->read() as $family) {
        $families[$family->getName()] = $family;
    }

    return $families;
}

/**
 * @return array<string, string>
 */
function seriesFields(string $key): array
{
    /** @var array<string, string> $fields */
    $fields = app(Redis::class)->connection()->command('HGETALL', [$key]);

    return $fields;
}

it('reparte los fichajes por origen con las tres etiquetas de RF-IN-08', function (): void {
    $scans = new RedisScanMetrics(app(Redis::class));
    $corrections = new RedisCorrectionMetrics(app(Redis::class));

    $scans->scanOriginRecorded(ScanOrigin::QR_KIOSK);
    $scans->scanOriginRecorded(ScanOrigin::QR_KIOSK);
    $scans->scanOriginRecorded(ScanOrigin::PIN_KIOSK);
    $corrections->manualEntryAdded();

    expect(RedisScanMetrics::SCANS_BY_ORIGIN)
        // La invariante de la que depende el lector: el sufijo de la clave es,
        // literalmente, el nombre de la serie del §8.2.
        ->toBe(MetricCatalogue::KEY_PREFIX.'scans_by_origin_total')
        ->and(seriesFields(RedisScanMetrics::SCANS_BY_ORIGIN))->toBe([
            'origin=qr' => '2',
            'origin=pin' => '1',
            'origin=manual' => '1',
        ]);
})->group('RF-IN-08');

it('el alta manual escribe en la misma serie que el fichaje con tarjeta', function (): void {
    // Dos adaptadores distintos, una sola serie. Si `RedisCorrectionMetrics`
    // declarara su propia constante, dejarian de coincidir en cuanto alguien
    // renombrara una — y el reparto por origen se partiria en dos series que
    // Grafana no sabe sumar.
    (new RedisCorrectionMetrics(app(Redis::class)))->manualEntryAdded();

    expect(seriesFields(RedisScanMetrics::SCANS_BY_ORIGIN))->toBe(['origin=manual' => '1']);
})->group('RF-IN-08');

it('no cuenta como fichaje lo que viene de una importacion', function (): void {
    // Son datos que venian de otro sistema al poner en marcha la instalacion:
    // nadie los ficho, y contarlos hincharia el reparto del primer dia.
    (new RedisScanMetrics(app(Redis::class)))->scanOriginRecorded(ScanOrigin::IMPORT);

    expect(seriesFields(RedisScanMetrics::SCANS_BY_ORIGIN))->toBe([]);
})->group('RF-IN-08');

it('la correccion de un tramo existente no cuenta como fichaje manual', function (): void {
    // `manualEntryAdded()` solo lo llama el ALTA. Rectificar un tramo que ya
    // existia no crea ninguna jornada: ese fichaje ya se conto cuando ocurrio,
    // con su origen de verdad.
    (new RedisCorrectionMetrics(app(Redis::class)))->correctionRecorded('OLVIDO_FICHAJE_SALIDA');

    expect(seriesFields(RedisScanMetrics::SCANS_BY_ORIGIN))->toBe([]);
})->group('RF-IN-08');

it('publica los hallazgos de la revision nocturna por tipo', function (): void {
    (new RedisAnomalyMetrics(app(Redis::class)))->anomaliesDetected([
        'missing_clock_out' => 3,
        'excessive_shift' => 1,
    ]);

    expect(RedisAnomalyMetrics::ANOMALIES_TOTAL)
        ->toBe(MetricCatalogue::KEY_PREFIX.'anomalous_patterns_detected_total')
        ->and(seriesFields(RedisAnomalyMetrics::ANOMALIES_TOTAL))->toBe([
            'pattern=missing_clock_out' => '3',
            'pattern=excessive_shift' => '1',
        ]);
})->group('RF-PR-06');

it('una noche sin hallazgos no escribe nada', function (): void {
    // Lo normal. Escribir ceros por tipo llenaria la serie de valores que no
    // aportan y haria creer que la revision encontro algo.
    (new RedisAnomalyMetrics(app(Redis::class)))->anomaliesDetected([]);

    expect(seriesFields(RedisAnomalyMetrics::ANOMALIES_TOTAL))->toBe([]);
})->group('RF-PR-06');

it('el lector vuelve a componer las series nuevas con sus etiquetas', function (): void {
    (new RedisScanMetrics(app(Redis::class)))->scanOriginRecorded(ScanOrigin::PIN_KIOSK);
    (new RedisAnomalyMetrics(app(Redis::class)))->anomaliesDetected(['missing_clock_out' => 2]);

    $families = familiesByName();

    expect($families)->toHaveKey('scans_by_origin_total')
        ->and($families)->toHaveKey('anomalous_patterns_detected_total');

    $rendered = (new RenderTextFormat)->render([
        $families['scans_by_origin_total'],
        $families['anomalous_patterns_detected_total'],
    ]);

    expect($rendered)
        ->toContain('scans_by_origin_total{origin="pin"} 1')
        ->toContain('anomalous_patterns_detected_total{pattern="missing_clock_out"} 2');
})->group('RF-IN-08', 'RF-PR-06');

it('encuentra las series que viven como claves sueltas, con el prefijo de la instalacion', function (): void {
    // La trampa de `phpredis`: el patron `MATCH` de un `SCAN` NO lleva el
    // prefijo global, y las claves que devuelve SI. Con cualquiera de los dos
    // detalles mal, esta familia y los cuatro histogramas con etiquetas
    // desaparecen de `/metrics` sin un solo error.
    $connection = app(Redis::class)->connection();
    $key = MetricCatalogue::KEY_PREFIX.'license_limit_exceeded_total:limit=employees';

    $connection->command('INCRBY', [$key, 1]);

    try {
        $families = familiesByName();

        expect($families)->toHaveKey('license_limit_exceeded_total');

        $rendered = (new RenderTextFormat)->render([$families['license_limit_exceeded_total']]);

        expect($rendered)->toContain('license_limit_exceeded_total{limit="employees"}');
    } finally {
        $connection->command('DEL', [$key]);
    }
})->group('RQ-06');

it('no lanza cuando Redis no responde', function (): void {
    // Regla dura 19 en el camino del scrape: una averia de metricas no puede
    // convertirse en un 5xx, porque Prometheus lo leeria como «la aplicacion no
    // responde» y alguien iria a reiniciar un sistema que esta fichando.
    expect((new RedisMetricReader(new UnavailableRedis, 'kronoqr-database-'))->read())->toBe([]);

    (new RedisScanMetrics(new UnavailableRedis))->scanOriginRecorded(ScanOrigin::QR_KIOSK);
    (new RedisAnomalyMetrics(new UnavailableRedis))->anomaliesDetected(['missing_clock_out' => 1]);
    (new RedisCorrectionMetrics(new UnavailableRedis))->manualEntryAdded();
})->group('RQ-06');
