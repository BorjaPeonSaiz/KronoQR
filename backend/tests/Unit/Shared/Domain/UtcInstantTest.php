<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use App\Modules\Shared\Domain\ValueObject\UtcWallTime;

/*
 * **El unico formateador del instante del contrato** (`UtcTimestamp`, regla
 * dura 3, RN-04).
 *
 * La trampa que justifica que exista: en `format('…\Z')` la `Z` es literal. Un
 * objeto que llega en otra zona se escribe con su hora local y una `Z` detras,
 * y el instante queda desplazado sin que nada falle. Habia ~50 copias del
 * patron y la mitad no pasaba antes a UTC; aqui se prueba que la conversion
 * ocurre siempre, con cada precision.
 */

it('pasa a UTC antes de escribir la Z, venga en la zona que venga', function (UtcPrecision $precision, string $expected): void {
    // 07:30:15,123456 en Madrid (verano, +02:00) son las 05:30:15,123456 UTC.
    $madrid = new DateTimeImmutable('2026-08-14 07:30:15.123456', new DateTimeZone('Europe/Madrid'));

    expect(UtcInstant::of($madrid, $precision))->toBe($expected)
        ->and(UtcInstant::format($madrid, $precision))->toBe($expected);
})->with([
    'microsegundos' => [UtcPrecision::Microseconds, '2026-08-14T05:30:15.123456Z'],
    'milisegundos' => [UtcPrecision::Milliseconds, '2026-08-14T05:30:15.123Z'],
    'segundos' => [UtcPrecision::Seconds, '2026-08-14T05:30:15Z'],
])->group('RN-04', 'RQ-06');

it('escribe microsegundos si no se pide otra precision', function (): void {
    $instant = new DateTimeImmutable('2026-01-02 03:04:05.000007', new DateTimeZone('UTC'));

    expect(UtcInstant::of($instant))->toBe('2026-01-02T03:04:05.000007Z')
        ->and(UtcInstant::format($instant))->toBe('2026-01-02T03:04:05.000007Z')
        ->and(UtcInstant::FORMAT)->toBe(UtcPrecision::Microseconds->value);
})->group('RN-04', 'RQ-06');

it('cumple el patron del esquema UtcTimestamp en las tres precisiones', function (UtcPrecision $precision): void {
    // El patron, copiado de `docs/api/openapi.yaml`: `+00:00` no vale.
    $instant = new DateTimeImmutable('2026-10-25 02:30:00.5', new DateTimeZone('Atlantic/Canary'));

    expect(UtcInstant::of($instant, $precision))
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/');
})->with(UtcPrecision::cases())->group('RQ-06');

it('un campo anulable sigue siendo nulo', function (UtcPrecision $precision): void {
    expect(UtcInstant::format(null, $precision))->toBeNull();
})->with(UtcPrecision::cases())->group('RQ-06');

it('el nombre de fichero tambien pasa a UTC', function (): void {
    $madrid = new DateTimeImmutable('2026-09-08 11:09:05', new DateTimeZone('Europe/Madrid'));

    expect(UtcInstant::compact($madrid))->toBe('20260908T090905Z');
})->group('RN-04');

it('lee un instante de la base de datos y lo escribe en UTC', function (mixed $value, ?string $expected): void {
    expect(UtcInstant::fromDatabase($value))->toBe($expected);
})->with([
    'texto de PostgreSQL' => ['2026-09-08 09:07:33.123456+00', '2026-09-08T09:07:33.123456Z'],
    'texto con otro desplazamiento' => ['2026-09-08 11:07:33+02', '2026-09-08T09:07:33.000000Z'],
    'texto sin zona, que es UTC' => ['2026-09-08 09:07:33', '2026-09-08T09:07:33.000000Z'],
    'objeto en otra zona' => [new DateTimeImmutable('2026-09-08 11:07:33', new DateTimeZone('Europe/Madrid')), '2026-09-08T09:07:33.000000Z'],
    'nulo' => [null, null],
    'cadena vacia' => ['', null],
    'un numero' => [1_757_322_453, null],
    'texto que no es una fecha' => ['no es una fecha', null],
])->group('RN-04');

it('escribe la hora rotulada «UTC» en UTC aunque el instante llegue con desfase', function (UtcWallTime $shape, string $expected): void {
    // 02:30:15 en Madrid el 24 de octubre de 2026, todavia en verano (+02:00):
    // son las 00:30:15 UTC. Sin conversion saldria «02:30 UTC», dos horas mal.
    $madrid = new DateTimeImmutable('2026-10-24 02:30:15', new DateTimeZone('Europe/Madrid'));

    expect(UtcInstant::wallTime($madrid, $shape))->toBe($expected);
})->with([
    'minuto' => [UtcWallTime::Minute, '2026-10-24 00:30'],
    'segundo' => [UtcWallTime::Second, '2026-10-24 00:30:15'],
    'hora del dia' => [UtcWallTime::TimeOfDay, '00:30'],
    'sello de fichero' => [UtcWallTime::FileStamp, '20261024-003015'],
])->group('RN-04');

it('la hora rotulada va al minuto si no se pide otra forma', function (): void {
    $canarias = new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('Atlantic/Canary'));

    expect(UtcInstant::wallTime($canarias))->toBe('2026-12-31 23:59');
})->group('RN-04');

it('lee solo un instante escrito, nunca una expresion relativa que mire el reloj (V1-AR-2)', function (string $value): void {
    // Con el analizador libre de PHP, cada una de estas daba la hora del
    // proceso o un dia desplazado: el dominio leia el reloj del sistema a
    // traves de una cadena (regla dura 2).
    expect(UtcInstant::parse($value))->toBeNull()
        ->and(UtcInstant::fromDatabase($value))->toBeNull();
})->with([
    'ahora' => ['now'],
    'mañana' => ['tomorrow'],
    'dentro de una semana' => ['+1 week'],
    'hoy a mediodia' => ['today noon'],
    'marca de Unix' => ['@1757322453'],
    'solo la fecha' => ['2026-09-08'],
    'sin segundos' => ['2026-09-08T09:07Z'],
    'siete decimales' => ['2026-09-08T09:07:33.1234567Z'],
    'el 31 de febrero' => ['2026-02-31T10:00:00Z'],
    'la hora 25' => ['2026-09-08T25:00:00Z'],
    'el minuto 60' => ['2026-09-08T09:60:00Z'],
    'un desfase de 15 horas' => ['2026-09-08T09:07:33+15:00'],
    'un desfase con minuto 60' => ['2026-09-08T09:07:33+02:60'],
    'una zona con nombre' => ['2026-09-08T09:07:33 Europe/Madrid'],
    'texto delante' => ['x2026-09-08T09:07:33Z'],
    'texto detras' => ['2026-09-08T09:07:33Zx'],
    'cadena vacia' => [''],
])->group('RN-04', 'RL-04');

it('lee las formas ISO-8601 y de PostgreSQL y las deja en UTC', function (string $value, string $expected): void {
    $instant = UtcInstant::parse($value);

    expect($instant)->not->toBeNull()
        ->and($instant?->getTimezone()->getName())->toBe('UTC')
        ->and(UtcInstant::format($instant))->toBe($expected);
})->with([
    'con Z' => ['2026-09-08T09:07:33Z', '2026-09-08T09:07:33.000000Z'],
    'sin zona, que es UTC' => ['2026-09-08 09:07:33', '2026-09-08T09:07:33.000000Z'],
    'PostgreSQL con +00' => ['2026-09-08 09:07:33.123456+00', '2026-09-08T09:07:33.123456Z'],
    'un decimal' => ['2026-09-08T09:07:33.5Z', '2026-09-08T09:07:33.500000Z'],
    'desfase con dos puntos' => ['2026-09-08T11:07:33+02:00', '2026-09-08T09:07:33.000000Z'],
    'desfase sin dos puntos' => ['2026-09-08T11:37:33+0230', '2026-09-08T09:07:33.000000Z'],
    'desfase de horas' => ['2026-09-08 11:07:33+02', '2026-09-08T09:07:33.000000Z'],
    'desfase negativo' => ['2026-09-08T05:07:33-04:00', '2026-09-08T09:07:33.000000Z'],
    'desfase de 14 horas' => ['2026-09-08T23:07:33+14:00', '2026-09-08T09:07:33.000000Z'],
    'el 29 de febrero bisiesto' => ['2028-02-29T00:00:00Z', '2028-02-29T00:00:00.000000Z'],
])->group('RN-04', 'RL-04');
