<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\InvalidFutureTolerance;
use App\Modules\Attendance\Domain\Exception\ShiftMarkInFuture;
use App\Modules\Attendance\Domain\Policy\ManualEntryHorizon;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * F1 (RL-01, RL-04): el alta y la correccion manuales no admiten nada posterior
 * a la hora del servidor mas el margen `ATTENDANCE_FUTURE_TOLERANCE_MINUTES`.
 *
 * «Ahora» se inyecta (regla dura 2) y el margen llega resuelto (regla dura 14):
 * ninguna prueba de aqui da por sabido el valor de serie.
 */

function horizonteF1(string $now = '2026-03-14T10:00:00Z', int $minutes = 5): ManualEntryHorizon
{
    return ManualEntryHorizon::at(new DateTimeImmutable($now), $minutes);
}

function instanteF1(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso);
}

/** La excepcion que lanza `$accion`; si no lanza ninguna, la prueba falla. */
function rechazoF1(Closure $accion): ShiftMarkInFuture
{
    try {
        $accion();
    } catch (ShiftMarkInFuture $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba ShiftMarkInFuture y no se lanzo nada.');
}

it('admite una marca exactamente en el limite y rechaza la del segundo siguiente', function (): void {
    $horizon = horizonteF1();

    $horizon->assertTimesAllowed(ShiftTimes::open(instanteF1('2026-03-14T10:05:00Z')));

    expect(fn () => $horizon->assertTimesAllowed(ShiftTimes::open(instanteF1('2026-03-14T10:05:01Z'))))
        ->toThrow(ShiftMarkInFuture::class);
})->group('RF-PA-04', 'RL-04');

it('dice cual de las dos marcas es la futura', function (string $in, string $out, FutureMark $esperada): void {
    $exception = rechazoF1(fn () => horizonteF1()->assertTimesAllowed(ShiftTimes::closed(instanteF1($in), instanteF1($out))));

    expect($exception->mark)->toBe($esperada)
        ->and($exception->translationKey)->toBe('attendance.errors.mark_in_future')
        ->and($exception->parameters)->toBe(['minutes' => 5]);
})->with([
    'salida prevista' => ['2026-03-14T06:00:00Z', '2026-03-14T14:00:00Z', FutureMark::ClockOut],
    'jornada entera por adelantado' => ['2026-03-15T06:00:00Z', '2026-03-15T14:00:00Z', FutureMark::ClockIn],
])->group('RF-PA-04', 'RL-04');

it('admite un tramo cerrado del pasado y uno abierto que empezo hace un rato', function (): void {
    $horizon = horizonteF1();

    $horizon->assertTimesAllowed(ShiftTimes::closed(instanteF1('2026-03-13T22:00:00Z'), instanteF1('2026-03-14T06:00:00Z')));
    $horizon->assertTimesAllowed(ShiftTimes::open(instanteF1('2026-03-14T09:30:00Z')));

    expect($horizon->latestAcceptable->format(DATE_ATOM))->toBe('2026-03-14T10:05:00+00:00');
})->group('RF-PA-04', 'RL-04');

it('con margen cero no admite ni un segundo por delante del servidor', function (): void {
    $horizon = horizonteF1(minutes: 0);

    $horizon->assertTimesAllowed(ShiftTimes::open(instanteF1('2026-03-14T10:00:00Z')));

    expect(fn () => $horizon->assertTimesAllowed(ShiftTimes::open(instanteF1('2026-03-14T10:00:01Z'))))
        ->toThrow(ShiftMarkInFuture::class);
})->group('RF-PA-04', 'RL-04');

it('rechaza un margen negativo al construirse', function (): void {
    expect(fn () => horizonteF1(minutes: -1))->toThrow(InvalidFutureTolerance::class);
})->group('RF-PA-04');

it('exige la hora del servidor en UTC', function (): void {
    expect(fn () => ManualEntryHorizon::at(new DateTimeImmutable('2026-03-14T11:00:00+01:00'), 5))
        ->toThrow(InstantIsNotUtc::class);
})->group('RF-PA-04');

it('compara la jornada en la zona del centro y no en UTC', function (): void {
    $madrid = new DateTimeZone('Europe/Madrid');
    // 22:58 UTC del 14 son las 23:58 del 14 en Madrid; con cinco minutos de
    // margen el limite cae a las 00:03 del 15: la jornada del 15 se admite, la
    // del 16 no.
    $horizon = horizonteF1('2026-03-14T22:58:00Z');

    $horizon->assertWorkDateAllowed(WorkDate::fromIsoDate('2026-03-15', $madrid));
    $horizon->assertWorkDateAllowed(WorkDate::fromIsoDate('2026-03-01', $madrid));

    $exception = rechazoF1(fn () => $horizon->assertWorkDateAllowed(WorkDate::fromIsoDate('2026-03-16', $madrid)));

    expect($exception->mark)->toBe(FutureMark::WorkDate)
        ->and($exception->translationKey)->toBe('attendance.errors.work_date_in_future')
        ->and($exception->getMessage())->toContain('2026-03-16');
})->group('RF-PA-04', 'RL-04', 'RN-04');

it('sin margen, la jornada de manana en la zona del centro todavia no ha empezado', function (): void {
    // 22:58 UTC son las 23:58 en Madrid: el 15 aun no ha empezado alli, aunque
    // en Tokio ya sea el 15 por la mañana.
    $horizon = horizonteF1('2026-03-14T22:58:00Z', 0);

    $horizon->assertWorkDateAllowed(WorkDate::fromIsoDate('2026-03-15', new DateTimeZone('Asia/Tokyo')));

    expect(fn () => $horizon->assertWorkDateAllowed(WorkDate::fromIsoDate('2026-03-15', new DateTimeZone('Europe/Madrid'))))
        ->toThrow(ShiftMarkInFuture::class);
})->group('RF-PA-04', 'RN-04');
