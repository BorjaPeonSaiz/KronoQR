<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Infrastructure\Persistence\DatabasePeriodReportReader;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabase;

/*
 * El calendario del informe por periodo se estima con los dias que tiene
 * (RF-IN-01, RNF-P-05; V21-5-BE-3 de la 2.2.1).
 *
 * `generate_series(date, date, interval)` es la serie de `timestamptz`, sin
 * funcion de soporte: PostgreSQL la estimaba siempre en 1 000 filas. Con 6 308
 * personas, el calendario por la plantilla se estimaba en 6,3 millones de filas
 * para 189 240 reales, y el plan se elegia para ese tamaño (el JIT, que la 2.2.1
 * apago, lo pagaba entero). La serie de enteros estima exacto.
 *
 * No hace falta volumen: la estimacion de la serie no depende de las tablas.
 * Se capturan las dos consultas del lector que generan el calendario (las filas
 * y la cobertura de contratos) y se mira su nodo de `generate_series`.
 */

uses(RefreshDatabase::class);

it('estima el calendario del informe con los dias del periodo', function (string $from, string $to, int $days): void {
    $reader = new DatabasePeriodReportReader(DB::connection(), 60);
    $query = new PeriodReportQuery(
        AccessScope::unrestricted(),
        DateRange::between($from, $to),
        ReportGranularity::Month,
        ReportGrouping::Employee,
        null,
        null,
    );

    $queries = QueryPlans::capture(
        static function () use ($reader, $query): void {
            $reader->rows($query, 'Hotel');
            $reader->contractCoverage($query);
        },
        static fn (string $sql): bool => str_contains($sql, 'generate_series'),
    );

    expect($queries)->toHaveCount(2);

    foreach ($queries as $captured) {
        $series = array_values(array_filter(
            QueryPlans::nodes($captured),
            static fn (array $node): bool => ($node['Function Name'] ?? null) === 'generate_series',
        ));

        expect($series)->toHaveCount(1, 'El calendario no sale de una Function Scan de generate_series')
            ->and($series[0]['Plan Rows'] ?? null)->toBe($days);
    }
})->with([
    'un mes de 30 dias' => ['2026-09-01', '2026-09-30', 30],
    'el mes del cambio de hora de primavera' => ['2026-03-01', '2026-03-31', 31],
    'un año bisiesto entero' => ['2028-01-01', '2028-12-31', 366],
])->group('RF-IN-01', 'RNF-P-05');
