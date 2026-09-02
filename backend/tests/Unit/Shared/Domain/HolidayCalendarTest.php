<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\HolidayCalendar;

/*
 * El calendario de festivos, en el unico sitio donde se parsea (RF-PD-07).
 *
 * ## Por que existe este fichero
 *
 * Porque antes habia **cuatro** copias del mismo parseo —dos en adaptadores y
 * dos en objetos de valor— y ya habian divergido: el borde HTTP rechazaba un
 * festivo repetido y el nucleo lo deduplicaba en silencio. Peor: el filtro de los
 * adaptadores solo miraba que fueran cadenas, asi que un `'["navidad"]'` escrito
 * a mano llegaba hasta el objeto de valor y hacia estallar la pasada nocturna de
 * deteccion **entera**, dejando sin evaluar RN-10 y RN-11 en toda la instalacion.
 *
 * Lo que se fija aqui es la politica, que es una y esta escrita: **normalizar sin
 * lanzar nunca**, y decir que se descarto para que quien lea pueda avisar y quien
 * escriba pueda rechazar.
 */

it('ordena y deduplica un calendario correcto', function (): void {
    // Dos calendarios con los mismos festivos en distinto orden son el mismo
    // calendario: sin normalizar, reordenar la lista produciria un asiento de
    // auditoria que declara un cambio de umbral legal donde no lo hubo.
    $calendar = HolidayCalendar::of(['2026-12-25', '2026-01-01', '2026-12-25']);

    expect($calendar->days)->toBe(['2026-01-01', '2026-12-25'])
        ->and($calendar->hadDuplicates)->toBeTrue()
        ->and($calendar->rejected)->toBe([])
        ->and($calendar->isClean())->toBeFalse();
})->group('RF-PD-07');

it('nunca lanza, sea cual sea lo que le den', function (mixed $value, array $expected): void {
    // **La propiedad que sostiene todo lo demas.** Este objeto se construye
    // dentro de la pasada nocturna, que resuelve la politica antes del bucle y
    // sin `try`: una excepcion aqui apaga la revision diaria de la instalacion.
    $calendar = HolidayCalendar::of($value);

    expect($calendar->days)->toBe($expected);
})->with([
    'texto que no es una fecha' => [['navidad'], []],
    'formato espanol' => [['25/12/2026'], []],
    'dia que no existe' => [['2026-02-30'], []],
    'mes que no existe' => [['2026-13-01'], []],
    'cadena vacia' => [[''], []],
    'numeros' => [[20261225], []],
    'nulos' => [[null], []],
    'listas anidadas' => [[['2026-12-25']], []],
    'objeto en vez de lista' => [['dia' => '2026-12-25'], []],
    'no es ni una lista' => ['navidad', []],
    'nulo' => [null, []],
    'lista vacia' => [[], []],
    'lo bueno se conserva' => [['navidad', '2026-12-25'], ['2026-12-25']],
])->group('RF-PD-07');

it('anota lo que ha descartado, para que el descarte no sea silencioso', function (): void {
    // Lo que permite al adaptador dejar un aviso y al borde HTTP responder 422:
    // la misma normalizacion, dos politicas distintas encima.
    $calendar = HolidayCalendar::of(['navidad', '2026-12-25', '25/12/2026']);

    expect($calendar->rejected)->toBe(['navidad', '25/12/2026'])
        ->and($calendar->days)->toBe(['2026-12-25'])
        ->and($calendar->isClean())->toBeFalse();
})->group('RF-PD-07');

it('lee el texto del JSONB y aguanta un JSON roto', function (string $raw, array $expected): void {
    expect(HolidayCalendar::fromStoredJson($raw)->days)->toBe($expected);
})->with([
    'calendario correcto' => ['["2026-01-06","2026-12-25"]', ['2026-01-06', '2026-12-25']],
    'lista vacia' => ['[]', []],
    'JSON truncado' => ['["2026-01-06"', []],
    'texto suelto' => ['navidad', []],
    'nulo de JSON' => ['null', []],
])->group('RF-PD-07');

it('considera limpio solo lo que no habria que corregir', function (): void {
    expect(HolidayCalendar::of(['2026-12-25'])->isClean())->toBeTrue()
        ->and(HolidayCalendar::empty()->isClean())->toBeTrue()
        ->and(HolidayCalendar::of(['navidad'])->isClean())->toBeFalse()
        ->and(HolidayCalendar::of(['2026-12-25', '2026-12-25'])->isClean())->toBeFalse();
})->group('RF-PD-07');

it('descarta entero un objeto JSON y lo deja anotado', function (): void {
    // `{"navidad": "2026-12-25"}` no describe una lista de festivos: sacar de ahi
    // una fecha seria adivinar. Se descarta entero, pero **no en silencio**: el
    // adaptador necesita saber que hubo algo que descartar para dejar el aviso.
    $calendar = HolidayCalendar::of(['dia' => '2026-12-25']);

    expect($calendar->days)->toBe([])
        ->and($calendar->rejected)->toBe(['object'])
        ->and($calendar->hadDuplicates)->toBeFalse()
        ->and($calendar->isClean())->toBeFalse();
})->group('RF-PD-07');

it('descarta tambien un objeto JSON leido de la columna, y lo anota', function (): void {
    // La otra mitad: el JSONB llega como texto y hay que decodificarlo a arrays
    // asociativos, no a objetos. Si se decodificara a objetos, un `{"dia": ...}`
    // se leeria como «ilegible» sin distinguirlo de un JSON roto, y el aviso
    // perderia la unica pista de que la fila tiene forma de objeto.
    $calendar = HolidayCalendar::fromStoredJson('{"dia":"2026-12-25"}');

    expect($calendar->days)->toBe([])
        ->and($calendar->rejected)->toBe(['object'])
        ->and($calendar->isClean())->toBeFalse();
})->group('RF-PD-07');

it('distingue un JSON ilegible de un objeto: aquel no tiene nada que anotar', function (): void {
    $roto = HolidayCalendar::fromStoredJson('["2026-01-06"');

    expect($roto->days)->toBe([])
        ->and($roto->rejected)->toBe([])
        // Un JSON que no se puede leer no dice si sobraba algo o no: se lee como
        // calendario vacio y limpio, y el sintoma lo da la columna, no esto.
        ->and($roto->isClean())->toBeTrue();
})->group('RF-PD-07');
