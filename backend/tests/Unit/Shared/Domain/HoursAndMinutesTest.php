<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\ExportedDuration;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use App\Modules\Shared\Domain\ValueObject\HoursAndMinutes;

/*
 * **Un solo `HH:MM`** para la exportacion legal y los informes (RL-06,
 * RF-IN-04, RF-IN-05).
 *
 * Los casos de cada modulo siguen en `ReportedDurationTest` y en
 * `LegalExportFormatTest`; aqui se prueba la regla y que los dos objetos de
 * valor escriben exactamente lo mismo para el mismo numero de minutos.
 */

it('escribe horas y minutos con dos digitos, pasando de 24 y con el signo delante', function (int $minutes, string $expected): void {
    expect(HoursAndMinutes::ofMinutes($minutes)->toClockText())->toBe($expected)
        ->and(HoursAndMinutes::ofMinutes($minutes)->minutes)->toBe($minutes);
})->with([
    'cero' => [0, '00:00'],
    'nueve minutos' => [9, '00:09'],
    'una hora menos un minuto' => [59, '00:59'],
    'una hora' => [60, '01:00'],
    'siete y cuarenta y cinco' => [465, '07:45'],
    'un mes' => [10080, '168:00'],
    'un minuto de menos' => [-1, '-00:01'],
    'doce y media de menos' => [-750, '-12:30'],
])->group('RL-06', 'RF-IN-04');

it('la exportacion legal y el informe escriben lo mismo', function (int $minutes): void {
    $text = HoursAndMinutes::ofMinutes($minutes)->toClockText();

    expect(ExportedDuration::ofMinutes($minutes)->toClockText())->toBe($text)
        ->and(ReportedDuration::ofMinutes($minutes)->toClockText())->toBe($text);
})->with([0, 1, 59, 60, 61, 465, 1440, 1441, 10080, 120_000])->group('RL-06', 'RF-IN-04', 'RF-IN-05');
