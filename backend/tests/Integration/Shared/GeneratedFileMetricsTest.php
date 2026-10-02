<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\RedisGeneratedFileMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Tests\Support\Health\UnavailableRedis;

/*
 * Las cuatro series de los ficheros generados sobre Redis de verdad (ADR-045 §h,
 * condicion C10).
 *
 * Lo que se comprueba es el NOMBRE exacto de cada serie y que su unica etiqueta
 * es `class` con un valor del catalogo cerrado: son lo que leen las reglas de
 * alerta, y lo que garantiza que ninguna serie lleva un `uuid` o una ruta.
 */

/** @return list<string> */
function seriesDeFicherosGenerados(): array
{
    return [
        RedisGeneratedFileMetrics::ORPHANS_REMOVED_TOTAL,
        RedisGeneratedFileMetrics::REFUSED_TOTAL,
        RedisGeneratedFileMetrics::MISSING_TOTAL,
        RedisGeneratedFileMetrics::OVERDUE,
    ];
}

beforeEach(function (): void {
    app(Redis::class)->connection()->command('DEL', seriesDeFicherosGenerados());
});

it('publica las cuatro series con su nombre y solo la etiqueta class', function (): void {
    $metricas = new RedisGeneratedFileMetrics(app(Redis::class));

    $metricas->orphanRemoved(GeneratedFileClass::DataExportWork);
    $metricas->orphanRemoved(GeneratedFileClass::DataExportWork);
    $metricas->refused(GeneratedFileClass::ReportExport);
    $metricas->missing(GeneratedFileClass::DataExport);
    $metricas->overdue(GeneratedFileClass::LegalExportConsole, 3);
    $metricas->overdue(GeneratedFileClass::LegalExportConsole, 1);

    $leer = static fn (string $clave): mixed => app(Redis::class)->connection()->command('HGETALL', [$clave]);

    expect(seriesDeFicherosGenerados())->toBe([
        'kronoqr:metrics:generated_files_orphans_removed_total',
        'kronoqr:metrics:generated_files_refused_total',
        'kronoqr:metrics:generated_files_missing_total',
        'kronoqr:metrics:generated_files_overdue',
    ])
        ->and($leer(RedisGeneratedFileMetrics::ORPHANS_REMOVED_TOTAL))->toBe(['class=data_export_work' => '2'])
        ->and($leer(RedisGeneratedFileMetrics::REFUSED_TOTAL))->toBe(['class=report_export' => '1'])
        ->and($leer(RedisGeneratedFileMetrics::MISSING_TOTAL))->toBe(['class=data_export' => '1'])
        // Un gauge: el ultimo valor, no la suma.
        ->and($leer(RedisGeneratedFileMetrics::OVERDUE))->toBe(['class=legal_export_console' => '1']);
})->group('RQ-06', 'RL-15');

it('el catalogo de /metrics las declara con la unica etiqueta class', function (): void {
    $declaradas = array_values(array_filter(
        MetricCatalogue::all(),
        static fn (MetricDefinition $definicion): bool => str_starts_with($definicion->name, 'generated_files_'),
    ));

    expect(array_map(static fn (MetricDefinition $d): string => $d->name, $declaradas))->toBe([
        'generated_files_orphans_removed_total',
        'generated_files_refused_total',
        'generated_files_missing_total',
        'generated_files_overdue',
    ]);

    foreach ($declaradas as $definicion) {
        expect($definicion->labels)->toBe(['class']);
    }
})->group('RQ-06');

it('no rompe una purga cuando Redis no responde', function (): void {
    $metricas = new RedisGeneratedFileMetrics(new UnavailableRedis);

    $metricas->orphanRemoved(GeneratedFileClass::DataExport);
    $metricas->overdue(GeneratedFileClass::LegalExportConsole, 2);
})->group('RQ-06')->throwsNoExceptions();
