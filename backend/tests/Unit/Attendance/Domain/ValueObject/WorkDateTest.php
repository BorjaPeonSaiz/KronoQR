<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\InvalidWorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Time\Instants;

/*
 * La jornada: fecha civil EN LA ZONA DEL CENTRO (RN-05).
 *
 * Un «2026-03-14» a secas no dice si las 23:30 UTC de ese dia son ese dia o el
 * siguiente, y esa ambiguedad es la que decide a que jornada se imputan ocho
 * horas de turno de noche.
 */

it('resuelve la fecha civil en la zona del centro y no en UTC', function (string $utcInstant, string $expectedIsoDate): void {
    $workDate = WorkDate::fromInstant(Instants::utc($utcInstant), Instants::madrid());

    expect($workDate->isoDate)->toBe($expectedIsoDate);
})->with([
    'las 23:30 UTC del 14 son ya el 15 en Madrid' => ['2026-03-14 23:30', '2026-03-15'],
    'las 22:59 UTC del 14 siguen siendo el 14 en Madrid' => ['2026-03-14 22:59', '2026-03-14'],
    'la medianoche UTC del 15 es el 15 en Madrid' => ['2026-03-15 00:00', '2026-03-15'],
])->group('RN-05', 'RN-04', 'RQ-01');

it('resuelve la fecha civil correctamente la noche del salto de octubre', function (string $utcInstant, string $expectedIsoDate): void {
    // El 25 de octubre de 2026 los relojes de Madrid retroceden a las 03:00 CEST.
    // Mirar el instante desde otra zona no lo mueve: solo cambia el cristal.
    $workDate = WorkDate::fromInstant(Instants::utc($utcInstant), Instants::madrid());

    expect($workDate->isoDate)->toBe($expectedIsoDate);
})->with([
    'las 21:00 UTC del 24, que son las 23:00 CEST' => ['2026-10-24 21:00', '2026-10-24'],
    'las 22:30 UTC del 24, que son las 00:30 CEST del 25' => ['2026-10-24 22:30', '2026-10-25'],
    'las 00:30 UTC del 25, que son las 02:30 CEST' => ['2026-10-25 00:30', '2026-10-25'],
    'las 01:30 UTC del 25, que son las 02:30 CET tras el salto' => ['2026-10-25 01:30', '2026-10-25'],
])->group('RN-05', 'RN-09');

it('resuelve la fecha civil correctamente la noche del salto de marzo', function (string $utcInstant, string $expectedIsoDate): void {
    // El 29 de marzo de 2026 los relojes de Madrid saltan de 02:00 CET a 03:00 CEST.
    $workDate = WorkDate::fromInstant(Instants::utc($utcInstant), Instants::madrid());

    expect($workDate->isoDate)->toBe($expectedIsoDate);
})->with([
    'las 22:00 UTC del 28, que son las 23:00 CET' => ['2026-03-28 22:00', '2026-03-28'],
    'las 23:30 UTC del 28, que son las 00:30 CET del 29' => ['2026-03-28 23:30', '2026-03-29'],
    'las 01:30 UTC del 29, que son las 03:30 CEST' => ['2026-03-29 01:30', '2026-03-29'],
])->group('RN-05', 'RN-09');

it('rechaza resolver la jornada desde un instante que no viene en UTC', function (): void {
    $inMadrid = new DateTimeImmutable('2026-03-14 23:30', Instants::madrid());

    expect(fn (): WorkDate => WorkDate::fromInstant($inMadrid, Instants::madrid()))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04');

it('rechaza una fecha que no existe en el calendario', function (string $isoDate): void {
    expect(fn (): WorkDate => WorkDate::fromIsoDate($isoDate, Instants::madrid()))
        ->toThrow(InvalidWorkDate::class);
})->with([
    'el 30 de febrero' => ['2026-02-30'],
    'el mes trece' => ['2026-13-01'],
    'el dia cero' => ['2026-01-00'],
    'sin ceros a la izquierda' => ['2026-3-14'],
    'con la hora pegada' => ['2026-03-14T00:00:00Z'],
    'vacia' => [''],
])->group('RN-05');

it('acepta el 29 de febrero de un ano bisiesto', function (): void {
    expect(WorkDate::fromIsoDate('2028-02-29', Instants::madrid())->isoDate)->toBe('2028-02-29');
})->group('RN-05');

it('rechaza el 29 de febrero de un ano que no es bisiesto', function (): void {
    expect(fn (): WorkDate => WorkDate::fromIsoDate('2026-02-29', Instants::madrid()))
        ->toThrow(InvalidWorkDate::class);
})->group('RN-05');

it('reconoce como la misma jornada la misma fecha en la misma zona', function (): void {
    $one = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());
    $other = WorkDate::fromInstant(Instants::utc('2026-03-14 12:00'), Instants::madrid());

    expect($one->equals($other))->toBeTrue();
})->group('RN-05');

it('no confunde la misma fecha civil de dos centros en husos distintos', function (): void {
    // daily_totals se indexa por empleado, y el empleado pertenece a un centro:
    // el 2026-03-14 de Madrid y el de Canarias no son la misma jornada.
    $peninsula = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());
    $canarias = WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Atlantic/Canary'));

    expect($peninsula->equals($canarias))->toBeFalse();
})->group('RN-05');

it('no confunde dos fechas consecutivas de la misma zona', function (): void {
    $friday = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());
    $saturday = WorkDate::fromIsoDate('2026-03-15', Instants::madrid());

    expect($friday->equals($saturday))->toBeFalse();
})->group('RN-05');
