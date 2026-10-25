<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Support\SiteWallClock;

/*
 * **La unica conversion UTC → zona del centro de Reporting y de Kiosk** (RN-04,
 * regla dura 3).
 *
 * En 2026 Madrid adelanta el reloj el 29 de marzo a las 01:00 UTC (de las 02:00
 * a las 03:00) y lo atrasa el 25 de octubre a las 01:00 UTC (de las 03:00 a las
 * 02:00). Que PostgreSQL diga lo mismo lo prueba
 * `SiteWallClockMatchesPostgresTest`; aqui, que PHP diga lo correcto.
 */

it('escribe la hora de pared del centro', function (string $utc, string $zone, string $minute, string $withOffset, string $offset): void {
    $clock = new SiteWallClock($zone);
    $instant = new DateTimeImmutable($utc);

    expect($clock->minute($instant))->toBe($minute)
        ->and($clock->withOffset($instant))->toBe($withOffset)
        ->and($clock->offset($instant))->toBe($offset);
})->with([
    'invierno en Madrid' => ['2026-01-15T07:00:00Z', 'Europe/Madrid', '2026-01-15 08:00', '2026-01-15T08:00:00.000000+01:00', 'UTC+01:00'],
    'verano en Madrid' => ['2026-07-15T07:00:00.25Z', 'Europe/Madrid', '2026-07-15 09:00', '2026-07-15T09:00:00.250000+02:00', 'UTC+02:00'],
    'el ultimo minuto antes del salto de marzo' => ['2026-03-29T00:59:00Z', 'Europe/Madrid', '2026-03-29 01:59', '2026-03-29T01:59:00.000000+01:00', 'UTC+01:00'],
    'el salto de marzo: de las 02:00 a las 03:00' => ['2026-03-29T01:00:00Z', 'Europe/Madrid', '2026-03-29 03:00', '2026-03-29T03:00:00.000000+02:00', 'UTC+02:00'],
    'la primera vez que son las 02:30 en octubre' => ['2026-10-25T00:30:00Z', 'Europe/Madrid', '2026-10-25 02:30', '2026-10-25T02:30:00.000000+02:00', 'UTC+02:00'],
    'la segunda vez que son las 02:30 en octubre' => ['2026-10-25T01:30:00Z', 'Europe/Madrid', '2026-10-25 02:30', '2026-10-25T02:30:00.000000+01:00', 'UTC+01:00'],
    'Canarias, una hora menos' => ['2026-03-29T01:00:00Z', 'Atlantic/Canary', '2026-03-29 02:00', '2026-03-29T02:00:00.000000+01:00', 'UTC+01:00'],
    'medianoche que cambia el dia' => ['2026-12-31T23:30:00Z', 'Europe/Madrid', '2027-01-01 00:30', '2027-01-01T00:30:00.000000+01:00', 'UTC+01:00'],
    'UTC tal cual' => ['2026-05-01T12:34:56Z', 'UTC', '2026-05-01 12:34', '2026-05-01T12:34:56.000000+00:00', 'UTC+00:00'],
])->group('RN-04', 'RF-ID-05');

it('rechaza una zona que no existe en lugar de escribir otra hora', function (): void {
    expect(static fn (): SiteWallClock => new SiteWallClock('Europe/Atlantida'))
        ->toThrow(Exception::class);
})->group('RN-04');

it('sella la hora de pared con el patron del idioma y siempre con su desfase', function (string $utc, string $pattern, string $stamp): void {
    // La consola de salud de los quioscos escribe el ultimo contacto con el
    // formato de su idioma. Sin el desfase, las dos 02:30 de octubre serian la
    // misma hora escrita.
    expect(new SiteWallClock('Europe/Madrid')->stamp(new DateTimeImmutable($utc), $pattern))->toBe($stamp);
})->with([
    'la primera vez que son las 02:30 en octubre' => ['2026-10-25T00:30:00Z', 'd/m/Y H:i:s', '25/10/2026 02:30:00 UTC+02:00'],
    'la segunda vez que son las 02:30 en octubre' => ['2026-10-25T01:30:00Z', 'd/m/Y H:i:s', '25/10/2026 02:30:00 UTC+01:00'],
    'con el patron en ingles' => ['2026-09-09T11:59:30Z', 'Y-m-d H:i:s', '2026-09-09 13:59:30 UTC+02:00'],
])->group('RN-04', 'RF-PA-07');
