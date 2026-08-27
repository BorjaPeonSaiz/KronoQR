<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InvalidClockingPolicy;
use App\Modules\Attendance\Domain\Policy\ClockingPolicy;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Time\Instants;

/*
 * RN-07 y RN-08: cuando la duracion de un tramo cerrado pide revision humana.
 *
 * Los dos umbrales llegan por constructor (regla dura 14) y ninguna prueba los
 * da por sabidos: la que va a por un limite lo escribe como numero, no como
 * calculo.
 *
 * Ninguna de las dos reglas cierra ni rechaza nada. La politica clasifica; el
 * tramo ya esta cerrado con las marcas que el empleado produjo.
 */

it('marca como corto un tramo por debajo de la duracion minima computable', function (int $minutes, bool $tooShort): void {
    $policy = ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build();

    expect($policy->isTooShort(WorkedDuration::ofMinutes($minutes)))->toBe($tooShort);
})->with([
    'cero minutos' => [0, true],
    'un minuto, que es el umbral' => [1, false],
    'dos minutos' => [2, false],
])->group('RN-07', 'RQ-01');

it('no marca como corto un tramo de 60 segundos y si uno de 59', function (string $clockedOutAt, bool $tooShort): void {
    // El limite de RN-07 medido sobre marcas reales: la duracion llega ya
    // truncada a minutos, asi que 59 s son 0 min y 60 s son 1 min.
    $policy = ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build();
    $worked = (new TimeRange(Instants::utc('2026-03-14 08:00:00'), Instants::utc($clockedOutAt)))->duration();

    expect($policy->isTooShort($worked))->toBe($tooShort);
})->with([
    'cincuenta y nueve segundos' => ['2026-03-14 08:00:59', true],
    'sesenta segundos' => ['2026-03-14 08:01:00', false],
])->group('RN-07');

it('marca como largo un tramo por encima de la duracion anomala', function (int $minutes, bool $tooLong): void {
    // 12 h configuradas: 11:59 y 12:00 son normales, 12:01 es anomalo. El umbral
    // exacto todavia no lo es; se supera al pasarlo.
    $policy = ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build();

    expect($policy->isTooLong(WorkedDuration::ofMinutes($minutes)))->toBe($tooLong);
})->with([
    'once horas y cincuenta y nueve minutos' => [719, false],
    'doce horas exactas' => [720, false],
    'doce horas y un minuto' => [721, true],
    'trece horas' => [780, true],
])->group('RN-08', 'RQ-01');

it('no clasifica como anomalo un tramo de duracion corriente', function (): void {
    $policy = ClockingPolicyFactory::standard();

    expect($policy->anomaliesFor(WorkedDuration::ofMinutes(480)))->toBe([]);
})->group('RN-07', 'RN-08');

it('devuelve el tramo corto como anomalia y no lo descarta', function (): void {
    $policy = ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build();

    expect($policy->anomaliesFor(WorkedDuration::ofMinutes(0)))->toBe([ShiftAnomaly::SHORT_SHIFT]);
})->group('RN-07');

it('devuelve el tramo largo como anomalia', function (): void {
    $policy = ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build();

    expect($policy->anomaliesFor(WorkedDuration::ofMinutes(721)))->toBe([ShiftAnomaly::LONG_SHIFT]);
})->group('RN-08');

it('rechaza una politica cuyo minimo computable supera el umbral anomalo', function (): void {
    // Clasificaria todo tramo como corto Y largo a la vez. Se rechaza al
    // construirla, que es al arrancar con una configuracion mala, y no meses
    // despues en el informe de una persona.
    expect(fn (): ClockingPolicy => new ClockingPolicy(
        WorkedDuration::ofMinutes(721),
        WorkedDuration::ofMinutes(720),
    ))->toThrow(InvalidClockingPolicy::class);
})->group('RN-07', 'RN-08');

it('acepta una politica cuyo minimo computable iguala el umbral anomalo', function (): void {
    $policy = new ClockingPolicy(WorkedDuration::ofMinutes(720), WorkedDuration::ofMinutes(720));

    expect($policy->anomaliesFor(WorkedDuration::ofMinutes(720)))->toBe([]);
})->group('RN-07', 'RN-08');

it('aplica los umbrales que recibe y no unos de serie escritos en el codigo', function (): void {
    // Regla dura 14 y ADR-017: un hotel con turnos de 10 h y otro con turnos de
    // 12 h se atienden con el mismo binario. Diez horas y un minuto es anomalo
    // aqui y corriente en el perfil de serie.
    $tenHourSite = ClockingPolicyFactory::new()->withAnomalousAfterHours(10)->build();

    expect($tenHourSite->anomaliesFor(WorkedDuration::ofMinutes(601)))->toBe([ShiftAnomaly::LONG_SHIFT])
        ->and(ClockingPolicyFactory::standard()->anomaliesFor(WorkedDuration::ofMinutes(601)))->toBe([]);
})->group('RN-08');

it('expone los umbrales con los que se construyo', function (): void {
    $policy = ClockingPolicyFactory::new()
        ->withMinimumComputableMinutes(5)
        ->withAnomalousAfterMinutes(600)
        ->build();

    expect($policy->minimumComputableShift->minutes)->toBe(5)
        ->and($policy->anomalousShiftThreshold->minutes)->toBe(600);
})->group('RN-07', 'RN-08');
