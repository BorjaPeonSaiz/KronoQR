<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use Tests\Support\Time\Instants;

/*
 * El intervalo trabajado: RN-03 (orden), RN-04 (UTC), RN-02 (borde semiabierto)
 * y RN-09 (la duracion se mide sobre instantes absolutos).
 *
 * Suite Unit: dominio puro, sin framework y sin base de datos.
 */

it('rechaza una salida anterior a la entrada', function (): void {
    $clockedInAt = Instants::utc('2026-03-14 14:00');
    $clockedOutAt = Instants::utc('2026-03-14 13:59');

    expect(fn (): TimeRange => new TimeRange($clockedInAt, $clockedOutAt))
        ->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-03', 'RQ-01');

it('rechaza una salida exactamente igual a la entrada', function (): void {
    // RN-03 es *estrictamente* posterior. Un tramo de duracion cero no es un
    // tramo corto de RN-07: es un par de marcas que no pueden haber ocurrido.
    $sameInstant = Instants::utc('2026-03-14 14:00');

    expect(fn (): TimeRange => new TimeRange($sameInstant, $sameInstant))
        ->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-03', 'RQ-01');

it('acepta una salida un segundo posterior a la entrada', function (): void {
    $range = new TimeRange(Instants::utc('2026-03-14 14:00:00'), Instants::utc('2026-03-14 14:00:01'));

    expect($range->duration()->minutes)->toBe(0);
})->group('RN-03', 'RN-07');

it('rechaza un instante que no viene en UTC', function (): void {
    // Regla dura 3 y RN-04. La zona local en el agregado significa casi siempre
    // que alguien se salto la conversion, y el sintoma aparece meses despues.
    $inMadrid = new DateTimeImmutable('2026-03-14 14:00', Instants::madrid());

    expect(fn (): TimeRange => new TimeRange($inMadrid, Instants::utc('2026-03-14 15:00')))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04', 'RQ-01');

it('rechaza el instante de fin cuando no viene en UTC', function (): void {
    $inMadrid = new DateTimeImmutable('2026-03-14 16:00', Instants::madrid());

    expect(fn (): TimeRange => new TimeRange(Instants::utc('2026-03-14 14:00'), $inMadrid))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04');

it('acepta las formas de UTC que solo cambian de nombre', function (string $literal): void {
    // +00:00 y Z son el mismo instante escrito de otra manera: se comprueba el
    // desplazamiento, no el nombre de la zona.
    $range = new TimeRange(new DateTimeImmutable($literal), Instants::utc('2026-03-14 15:00'));

    expect($range->duration()->minutes)->toBe(60);
})->with([
    'sufijo Z' => ['2026-03-14T14:00:00Z'],
    'desplazamiento cero explicito' => ['2026-03-14T14:00:00+00:00'],
    'con microsegundos' => ['2026-03-14T14:00:00.000000Z'],
])->group('RN-04');

it('rechaza un desplazamiento que no es cero aunque la hora escrita sea la misma', function (string $literal): void {
    $instant = new DateTimeImmutable($literal);

    expect(fn (): TimeRange => new TimeRange($instant, Instants::utc('2026-03-14 23:00')))
        ->toThrow(InstantIsNotUtc::class);
})->with([
    'una hora por delante' => ['2026-03-14T14:00:00+01:00'],
    'cinco horas por detras' => ['2026-03-14T14:00:00-05:00'],
    'un solo minuto de desplazamiento' => ['2026-03-14T14:00:00+00:01'],
])->group('RN-04');

it('mide la duracion truncando los segundos sobrantes', function (string $end, int $expectedMinutes): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00:00'), Instants::utc($end));

    expect($range->duration()->minutes)->toBe($expectedMinutes);
})->with([
    'un segundo' => ['2026-03-14 08:00:01', 0],
    'cincuenta y nueve segundos' => ['2026-03-14 08:00:59', 0],
    'sesenta segundos' => ['2026-03-14 08:01:00', 1],
    'un minuto y cincuenta y nueve segundos' => ['2026-03-14 08:01:59', 1],
    'trece horas' => ['2026-03-14 21:00:00', 780],
])->group('RN-09', 'RQ-01');

it('no considera solape dos tramos que solo se tocan en el borde', function (): void {
    // El intervalo es [inicio, fin), igual que el tstzrange por defecto de
    // PostgreSQL: salir a las 14:00 y entrar a las 14:00 es legitimo.
    $morning = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));
    $afternoon = new TimeRange(Instants::utc('2026-03-14 14:00'), Instants::utc('2026-03-14 20:00'));

    expect($morning->overlaps($afternoon))->toBeFalse()
        ->and($afternoon->overlaps($morning))->toBeFalse();
})->group('RN-02', 'RQ-01');

it('considera solape un minuto de invasion en cualquiera de los dos sentidos', function (): void {
    $morning = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));
    $overlapping = new TimeRange(Instants::utc('2026-03-14 13:59'), Instants::utc('2026-03-14 20:00'));

    expect($morning->overlaps($overlapping))->toBeTrue()
        ->and($overlapping->overlaps($morning))->toBeTrue();
})->group('RN-02');

it('considera solape un tramo contenido enteramente en otro', function (): void {
    $whole = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 20:00'));
    $inside = new TimeRange(Instants::utc('2026-03-14 10:00'), Instants::utc('2026-03-14 12:00'));

    expect($whole->overlaps($inside))->toBeTrue()
        ->and($inside->overlaps($whole))->toBeTrue();
})->group('RN-02');

it('contiene su inicio y deja fuera su fin', function (string $instant, bool $contained): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));

    expect($range->contains(Instants::utc($instant)))->toBe($contained);
})->with([
    'un segundo antes del inicio' => ['2026-03-14 07:59:59', false],
    'el inicio exacto' => ['2026-03-14 08:00:00', true],
    'un segundo despues del inicio' => ['2026-03-14 08:00:01', true],
    'un segundo antes del fin' => ['2026-03-14 13:59:59', true],
    'el fin exacto' => ['2026-03-14 14:00:00', false],
])->group('RN-02');

it('sigue vivo despues de un instante solo si termina mas tarde', function (string $instant, bool $endsAfter): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));

    expect($range->endsAfter(Instants::utc($instant)))->toBe($endsAfter);
})->with([
    'un segundo antes del fin' => ['2026-03-14 13:59:59', true],
    'el fin exacto' => ['2026-03-14 14:00:00', false],
    'un segundo despues del fin' => ['2026-03-14 14:00:01', false],
])->group('RN-02');

it('reconoce como iguales dos intervalos con los mismos instantes', function (): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));
    $same = new TimeRange(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 14:00'));

    expect($range->equals($same))->toBeTrue();
})->group('RN-09');

it('distingue dos intervalos que difieren en un solo segundo', function (): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00:00'), Instants::utc('2026-03-14 14:00:00'));
    $shifted = new TimeRange(Instants::utc('2026-03-14 08:00:00'), Instants::utc('2026-03-14 14:00:01'));

    expect($range->equals($shifted))->toBeFalse();
})->group('RN-09');

it('distingue dos intervalos que difieren solo en su inicio', function (): void {
    $range = new TimeRange(Instants::utc('2026-03-14 08:00:00'), Instants::utc('2026-03-14 14:00:00'));
    $shifted = new TimeRange(Instants::utc('2026-03-14 08:00:01'), Instants::utc('2026-03-14 14:00:00'));

    expect($range->equals($shifted))->toBeFalse();
})->group('RN-09');
