<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\NegativeWorkedDuration;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;

/*
 * El tiempo trabajado: minutos, nunca negativo, y la unidad en la que se suma
 * el total del dia (RN-06, RN-09).
 */

it('no admite una duracion negativa', function (): void {
    expect(fn (): WorkedDuration => WorkedDuration::ofMinutes(-1))
        ->toThrow(NegativeWorkedDuration::class);
})->group('RN-09', 'RQ-01');

it('admite una duracion de cero minutos', function (): void {
    // Cero es legitimo: es lo que aporta un tramo abierto al total del dia.
    expect(WorkedDuration::ofMinutes(0)->minutes)->toBe(0);
})->group('RN-07');

it('expresa en minutos los umbrales que el negocio enuncia en horas', function (int $hours, int $expectedMinutes): void {
    expect(WorkedDuration::ofHours($hours)->minutes)->toBe($expectedMinutes);
})->with([
    'una hora' => [1, 60],
    'nueve horas de jornada ordinaria' => [9, 540],
    'doce horas de tramo anomalo' => [12, 720],
    'trece horas' => [13, 780],
])->group('RN-08');

it('empieza en cero', function (): void {
    expect(WorkedDuration::zero()->minutes)->toBe(0)
        ->and(WorkedDuration::zero()->isZero())->toBeTrue();
})->group('RN-06');

it('no considera cero un solo minuto', function (): void {
    expect(WorkedDuration::ofMinutes(1)->isZero())->toBeFalse();
})->group('RN-06');

it('suma dos duraciones sin modificar ninguna de las dos', function (): void {
    $morning = WorkedDuration::ofMinutes(360);
    $afternoon = WorkedDuration::ofMinutes(120);

    $total = $morning->plus($afternoon);

    expect($total->minutes)->toBe(480)
        ->and($morning->minutes)->toBe(360)
        ->and($afternoon->minutes)->toBe(120);
})->group('RN-06', 'RQ-01');

it('compara duraciones por debajo del umbral', function (int $minutes, int $threshold, bool $shorter): void {
    expect(WorkedDuration::ofMinutes($minutes)->isShorterThan(WorkedDuration::ofMinutes($threshold)))
        ->toBe($shorter);
})->with([
    'un minuto por debajo' => [0, 1, true],
    'justo en el umbral' => [1, 1, false],
    'un minuto por encima' => [2, 1, false],
])->group('RN-07');

it('compara duraciones por encima del umbral', function (int $minutes, int $threshold, bool $longer): void {
    expect(WorkedDuration::ofMinutes($minutes)->isLongerThan(WorkedDuration::ofMinutes($threshold)))
        ->toBe($longer);
})->with([
    'un minuto por debajo' => [719, 720, false],
    'justo en el umbral' => [720, 720, false],
    'un minuto por encima' => [721, 720, true],
])->group('RN-08');

it('reconoce como iguales dos duraciones de los mismos minutos', function (): void {
    expect(WorkedDuration::ofMinutes(480)->equals(WorkedDuration::ofHours(8)))->toBeTrue();
})->group('RN-06');

it('distingue dos duraciones que difieren en un minuto', function (): void {
    expect(WorkedDuration::ofMinutes(480)->equals(WorkedDuration::ofMinutes(479)))->toBeFalse();
})->group('RN-06');
