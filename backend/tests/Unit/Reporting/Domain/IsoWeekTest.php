<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Exception\InvalidIsoWeek;
use App\Modules\Reporting\Domain\ValueObject\IsoWeek;

/*
 * La semana ISO del resumen semanal (**RF-PR-05**, tarea 3.12).
 *
 * ## Por que esto merece pruebas propias
 *
 * Porque «la semana pasada» parece una resta de siete dias y no lo es. Los tres
 * casos que rompen la version ingenua —el año de la semana, la semana a caballo
 * del cambio de año y los años de 53 semanas— ocurren **una vez al año cada
 * uno**, que es justo la frecuencia con la que un fallo asi llega a produccion
 * sin que nadie lo vea venir. Un resumen rotulado «semana 1 de 2025» con los
 * datos de 2026, o un `--week=2026-W53` que resume en silencio la primera
 * semana de 2027, son errores que solo se detectan leyendo el correo.
 */

it('rotula la semana con su año ISO y no con el del dia', function (string $day, string $label): void {
    // El caso que rompe `format('Y')`: el 1 de enero de 2027 es viernes y
    // pertenece a la ultima semana de 2026; el 31 de diciembre de 2025 es
    // miercoles y pertenece a la primera de 2026.
    expect(IsoWeek::containing(new DateTimeImmutable($day))->label())->toBe($label);
})->with([
    'un martes cualquiera' => ['2026-09-15', '2026-W38'],
    'el lunes de esa semana' => ['2026-09-14', '2026-W38'],
    'el domingo de esa semana' => ['2026-09-20', '2026-W38'],
    'el primero de enero que es de la semana anterior' => ['2027-01-01', '2026-W53'],
    'el ultimo de diciembre que es de la semana siguiente' => ['2025-12-31', '2026-W01'],
])->group('RF-PR-05');

it('empieza en lunes y termina en domingo', function (): void {
    // ISO 8601, que es lo que el informe por periodo ya usa con
    // `granularity=week`: con el domingo como primer dia, el correo del lunes
    // hablaria de una semana distinta de la que enseña el panel.
    $week = IsoWeek::fromLabel('2026-W38');

    expect($week->isoStart())->toBe('2026-09-14')
        ->and($week->isoEnd())->toBe('2026-09-20')
        ->and($week->toRange()->isoFrom())->toBe('2026-09-14')
        ->and($week->toRange()->isoTo())->toBe('2026-09-20')
        ->and($week->toRange()->days())->toBe(7);
})->group('RF-PR-05');

it('retrocede una semana cruzando el cambio de año', function (string $from, string $previous): void {
    // La pasada del primer lunes de enero es la que descubre si «la semana
    // pasada» se calculo restando uno al contador: `2027-W01` menos uno seria
    // `2027-W00`, y la respuesta correcta es `2026-W53`.
    expect(IsoWeek::fromLabel($from)->previous()->label())->toBe($previous);
})->with([
    'dentro del mismo año' => ['2026-W38', '2026-W37'],
    'del primer lunes del año a la ultima semana del anterior' => ['2027-W01', '2026-W53'],
    'de un año de 52 semanas al anterior' => ['2026-W01', '2025-W52'],
])->group('RF-PR-05');

it('resuelve la semana pasada desde un instante de la zona del centro', function (): void {
    // Como lo hace el caso de uso: el lunes de la pasada, a las 06:00 UTC, en
    // Madrid ya son las 08:00 del mismo dia, y la semana que hay que resumir es
    // la que acaba de terminar.
    $now = new DateTimeImmutable('2026-09-21T06:00:00+00:00');
    $local = $now->setTimezone(new DateTimeZone('Europe/Madrid'));

    expect(IsoWeek::containing($local)->previous()->label())->toBe('2026-W38')
        ->and(IsoWeek::containing($local)->previous()->isoStart())->toBe('2026-09-14');
})->group('RF-PR-05');

it('la zona del centro decide la semana en el borde de la medianoche', function (): void {
    // 00:30 UTC del lunes es todavia domingo en Los Angeles, asi que «la semana
    // pasada» es una distinta segun se mire con la zona del servidor o con la
    // del centro. Es el mismo argumento por el que `absences_current` no usa
    // `CURRENT_DATE` (ADR-040).
    $now = new DateTimeImmutable('2026-09-21T00:30:00+00:00');

    expect(IsoWeek::containing($now->setTimezone(new DateTimeZone('UTC')))->previous()->label())
        ->toBe('2026-W38')
        ->and(IsoWeek::containing($now->setTimezone(new DateTimeZone('America/Los_Angeles')))->previous()->label())
        ->toBe('2026-W37');
})->group('RF-PR-05');

it('rechaza lo que no es una semana ISO', function (string $label): void {
    expect(fn (): IsoWeek => IsoWeek::fromLabel($label))->toThrow(InvalidIsoWeek::class);
})->with([
    'sin la W' => ['2026-38'],
    'con la semana cero' => ['2026-W00'],
    'con una semana que no existe en ningun año' => ['2026-W54'],
    // 2025 tiene 52 semanas ISO. `setISODate` reinterpretaria la 53 en silencio
    // como la primera de 2026, y el correo diria «semana 53 de 2025» sobre datos
    // de 2026.
    'con la semana 53 de un año que tiene 52' => ['2025-W53'],
    'con una fecha' => ['2026-09-14'],
    'vacio' => [''],
])->group('RF-PR-05');

it('acepta la semana 53 del año que si la tiene', function (): void {
    // 2026 empieza en jueves y por eso tiene 53 semanas ISO: el control de
    // arriba rechaza contra el calendario real y no contra una regla escrita a
    // ojo. La 53 de 2026 empieza el lunes 28 de diciembre.
    expect(IsoWeek::fromLabel('2026-W53')->isoStart())->toBe('2026-12-28')
        ->and(IsoWeek::fromLabel('2026-W53')->isoEnd())->toBe('2027-01-03');
})->group('RF-PR-05');
