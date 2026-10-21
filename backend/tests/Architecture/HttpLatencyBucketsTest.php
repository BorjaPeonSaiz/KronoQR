<?php

declare(strict_types=1);

use App\Http\Middleware\RecordHttpMetrics;
use Tests\Architecture\Support\Repo;

/*
 * TODO `le` QUE LEE UNA ALERTA, UN PANEL O EL SIMULACRO ES UN CUBO DEL
 * MIDDLEWARE (tarea 10.8 de la 2.3.0, R4-DV-06, RNF-D-01).
 *
 * Una consulta sobre `http_request_duration_seconds_bucket{le="2"}` con un
 * histograma sin cubo de 2 s no da error: no devuelve nada, y una alerta sin
 * datos no suena nunca. Es como `LatenciaDelFichajePorPinAlta` y
 * `LatenciaDelFichajePorLoteAlta` se quedaron vigilando 3 s cuando sus umbrales
 * eran 2 s y 10 s. Esta prueba ata los dos lados: si alguien quita un cubo del
 * middleware, o escribe una regla sobre uno que no existe, falla aqui y no en
 * casa del cliente.
 */

const HTTP_LATENCY_BUCKETS_CONSUMERS = [
    'infra/observability/prometheus/rules/api.yml',
    'infra/observability/prometheus/tests/api.test.yml',
    'infra/observability/grafana/dashboards/KronoQR/salud-api.json',
    'infra/drill/baseline.prom',
    'infra/drill/faults.tsv',
];

/**
 * Los cubos finitos que lee un fichero, en las lineas del histograma HTTP.
 *
 * Acepta `le="3"`, `le="3.0"`, `le=~"3(\\.0)?"` y sus versiones escapadas en
 * JSON (`le=~\"3(\\\\.0)?\"`).
 *
 * @return list<float>
 */
function httpLatencyBucketsReadBy(string $relative): array
{
    $found = [];

    foreach (preg_split('/\R/', Repo::contents($relative)) ?: [] as $line) {
        if (! str_contains($line, 'http_request_duration_seconds_bucket')) {
            continue;
        }

        preg_match_all('/le=~?\\\\?"(\d+(?:\.\d+)?)/', $line, $matches);

        foreach ($matches[1] as $bucket) {
            $found[] = (float) $bucket;
        }
    }

    return $found;
}

it('cada cubo que leen las alertas, sus pruebas, el panel y el simulacro existe en el middleware', function (string $relative): void {
    $read = httpLatencyBucketsReadBy($relative);

    expect($read)->not->toBe([], $relative.' no lee ningun cubo: la prueba no estaria mirando nada.');

    $missing = array_values(array_filter(
        array_unique($read, SORT_REGULAR),
        static fn (float $bucket): bool => ! in_array($bucket, RecordHttpMetrics::BUCKETS, true),
    ));

    expect($missing)->toBe([], $relative.' lee cubos que RecordHttpMetrics no escribe.');
})->with(HTTP_LATENCY_BUCKETS_CONSUMERS)->group('RNF-D-01', 'RNF-P-02');

it('el PIN y el lote se vigilan sobre los cubos de sus umbrales, 2 s y 10 s', function (): void {
    $rules = Repo::contents('infra/observability/prometheus/rules/api.yml');

    expect(RecordHttpMetrics::BUCKETS)->toContain(2.0)
        ->and(RecordHttpMetrics::BUCKETS)->toContain(10.0)
        ->and($rules)->toContain('route="attendance.scan.pin", le=~"2(\\\\.0)?"')
        ->and($rules)->toContain('route="attendance.scan.batch", le=~"10(\\\\.0)?"');
})->group('RNF-D-01', 'RNF-P-02');

it('los cubos van en orden y no se pierde ninguno de los que ya habia', function (): void {
    $sorted = RecordHttpMetrics::BUCKETS;
    sort($sorted);

    // Quitar un cubo deja sin datos a quien lo lea: los de la 2.2.1 se quedan.
    foreach ([0.025, 0.05, 0.1, 0.15, 0.3, 0.4, 1.0, 3.0] as $before) {
        expect(RecordHttpMetrics::BUCKETS)->toContain($before);
    }

    expect(RecordHttpMetrics::BUCKETS)->toBe($sorted);
})->group('RNF-D-01');
