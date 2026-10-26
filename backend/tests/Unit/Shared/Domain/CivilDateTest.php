<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Support\CivilDate;

/*
 * El comienzo de una fecha civil en una zona, sin pasar por el analizador libre
 * de PHP (regla dura 2, H4). Suite Unit: sin base de datos.
 */

it('da la medianoche de la fecha en su zona', function (string $isoDate, string $zone, string $expected): void {
    expect(CivilDate::midnight($isoDate, new DateTimeZone($zone))->format('Y-m-d\TH:i:s.uP'))->toBe($expected);
})->with([
    'UTC' => ['2026-10-26', 'UTC', '2026-10-26T00:00:00.000000+00:00'],
    'Madrid en invierno' => ['2026-01-15', 'Europe/Madrid', '2026-01-15T00:00:00.000000+01:00'],
    'el dia del salto de marzo en Madrid' => ['2026-03-29', 'Europe/Madrid', '2026-03-29T00:00:00.000000+01:00'],
    // El salto de Santiago es a medianoche: ese dia empieza a la 01:00, como
    // hacia el constructor, y sigue siendo el mismo dia (RN-09).
    'el dia sin medianoche en Santiago' => ['2026-09-06', 'America/Santiago', '2026-09-06T01:00:00.000000-03:00'],
    'el 29 de febrero de un bisiesto' => ['2028-02-29', 'UTC', '2028-02-29T00:00:00.000000+00:00'],
])->group('RN-09', 'RQ-01');

it('no toma nada del reloj del sistema', function (): void {
    // Sin el `!`, `createFromFormat` rellena la hora y los microsegundos con
    // los del momento actual. Dos llamadas separadas dan el mismo instante.
    $first = CivilDate::midnight('2026-10-26', new DateTimeZone('UTC'));
    usleep(1000);
    $second = CivilDate::midnight('2026-10-26', new DateTimeZone('UTC'));

    expect($first == $second)->toBeTrue()
        ->and($first->format('u'))->toBe('000000');
})->group('RQ-01');

it('rechaza lo que no es una fecha del calendario en vez de leer el reloj', function (string $texto): void {
    expect(static fn (): DateTimeImmutable => CivilDate::midnight($texto, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class, $texto);
})->with([
    'today' => ['today'],
    'now' => ['now'],
    'midnight' => ['midnight'],
    'vacio' => [''],
    'un desplazamiento' => ['+1 day'],
    'el 30 de febrero' => ['2026-02-30'],
    'con hora' => ['2026-10-26 08:00'],
    'sin ceros' => ['2026-1-5'],
])->group('RQ-01');
