<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exception\InvalidElapsedTime;
use App\Modules\Shared\Domain\ValueObject\ElapsedMinutes;
use Tests\Support\Time\Instants;

/*
 * **La unica regla de minutos del producto** (RN-09, RN-10).
 *
 * `between()` mide una duracion y no admite que acabe antes de empezar;
 * `gapBetween()` mide un hueco y devuelve `null` si lo siguiente empieza antes.
 * Las dos truncan a segundos antes de restar y a minutos despues de dividir:
 * es la semantica que tenian las cinco copias que sustituye, y cambiarla es una
 * decision del propietario.
 */

it('mide en minutos enteros truncando, sobre instantes', function (string $from, string $to, int $minutes): void {
    expect(ElapsedMinutes::between(new DateTimeImmutable($from), new DateTimeImmutable($to))->minutes)->toBe($minutes)
        ->and(ElapsedMinutes::gapBetween(new DateTimeImmutable($from), new DateTimeImmutable($to))?->minutes)->toBe($minutes);
})->with([
    'jornada redonda' => ['2026-10-07T06:00:00Z', '2026-10-07T14:00:00Z', 480],
    'segundos sueltos: 6 h 0 min 59 s son 360' => ['2026-10-07T06:00:00Z', '2026-10-07T12:00:59Z', 360],
    'un segundo antes del minuto' => ['2026-10-07T06:00:01Z', '2026-10-07T06:01:00Z', 0],
    'turno de noche' => ['2026-10-07T20:00:00Z', '2026-10-08T04:30:30Z', 510],
    // `getTimestamp()` trunca cada marca a su segundo antes de restar:
    // 08:00:00 → 09:00:00, 60 minutos, aunque hayan pasado 59 min 59,2 s.
    'fraccion de segundo' => ['2026-10-07T08:00:00.900Z', '2026-10-07T09:00:00.100Z', 60],
    'la misma zona con desplazamiento' => ['2026-10-07T08:00:00+02:00', '2026-10-07T06:30:00Z', 30],
])->group('RN-09', 'RN-10');

it('cero minutos es una medida, no la ausencia de medida', function (): void {
    $instant = Instants::utc('2026-03-19 00:00');

    expect(ElapsedMinutes::between($instant, $instant)->minutes)->toBe(0)
        ->and(ElapsedMinutes::gapBetween($instant, $instant)?->minutes)->toBe(0);
})->group('RN-09', 'RN-10');

it('mide el salto de marzo y el de octubre en tiempo real, no en horas de pared', function (string $from, string $to, int $minutes): void {
    expect(ElapsedMinutes::between(Instants::inMadrid($from), Instants::inMadrid($to))->minutes)->toBe($minutes)
        ->and(ElapsedMinutes::gapBetween(Instants::inMadrid($from), Instants::inMadrid($to))?->minutes)->toBe($minutes);
})->with([
    // 22:00 CET → 10:30 CEST: el reloj marca 12 h 30, han pasado 11 h 30.
    'adelanto de marzo' => ['2026-03-28 22:00', '2026-03-29 10:30', 690],
    // 22:00 CEST → 06:00 CET: el reloj marca 8 h, han pasado 9.
    'atraso de octubre' => ['2026-10-24 22:00', '2026-10-25 06:00', 540],
])->group('RN-09', 'RN-10');

it('una duracion que acaba antes de empezar es un error de quien llama', function (): void {
    expect(fn (): ElapsedMinutes => ElapsedMinutes::between(
        Instants::utc('2026-03-19 08:00'),
        Instants::utc('2026-03-19 07:59:59'),
    ))->toThrow(InvalidElapsedTime::class, 'A duration cannot end at 2026-03-19T07:59:59.000000+00:00, before its start at 2026-03-19T08:00:00.000000+00:00 (RN-09).');
})->group('RN-09');

it('un hueco negativo es un solape y no se mide', function (): void {
    // RN-02 responde del solape; medirlo daria el peor descanso posible.
    expect(ElapsedMinutes::gapBetween(Instants::utc('2026-03-19 08:00'), Instants::utc('2026-03-19 07:59:59')))->toBeNull();
})->group('RN-10');

it('dentro del mismo segundo nada es negativo, como antes de unificar la regla', function (): void {
    // La marca de llegada es anterior por microsegundos pero del mismo segundo:
    // la diferencia en segundos enteros es 0, y 0 es una medida.
    $from = new DateTimeImmutable('2026-03-19T08:00:00.900000Z');
    $to = new DateTimeImmutable('2026-03-19T08:00:00.100000Z');

    expect(ElapsedMinutes::between($from, $to)->minutes)->toBe(0)
        ->and(ElapsedMinutes::gapBetween($from, $to)?->minutes)->toBe(0);
})->group('RN-09', 'RN-10');
