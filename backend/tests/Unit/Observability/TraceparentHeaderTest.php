<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Support\TraceparentHeader;
use App\Support\Observability\Tracing\TraceContext;

/*
 * **LA CABECERA `traceparent` SE LEE EN UN SOLO SITIO, Y SE VALIDA ANTES DE
 * CREERLA** (doc 02 §8.1, RL-08, regla dura 21).
 *
 * ## Por que unitario
 *
 * Es una regla de forma sobre una cadena. Que el middleware la aplique de verdad
 * lo prueba `tests/Feature/Observability/TraceCorrelationTest.php`; lo que aqui
 * se fija es que la regla dice lo mismo para todos sus consumidores —el
 * middleware, `ServerErrorReporter` (RF-PD-15) y el processor de correlacion—,
 * que hasta la segunda vuelta de la tarea 3.1 tenian **tres copias que no
 * coincidian**.
 */

it('acepta un traceparent con la forma exacta del W3C', function (): void {
    $cabecera = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    expect(TraceparentHeader::valid($cabecera))->toBeTrue()
        ->and(TraceparentHeader::traceIdOf($cabecera))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
})->group('RL-08');

it('rechaza cualquier cosa que no tenga esa forma', function (mixed $cabecera): void {
    expect(TraceparentHeader::valid($cabecera))->toBeFalse()
        ->and(TraceparentHeader::traceIdOf($cabecera))->toBeNull();
})->with([
    'vacia' => [''],
    'texto suelto' => ['no-es-un-traceparent'],
    'nula' => [null],
    'un numero' => [42],
    'un array' => [['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']],
    'hexadecimal corto' => ['00-4bf92f35-00f067aa0ba902b7-01'],
    'con mayusculas' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'],
    // Sin anclar al final, una cabecera de 8 KB que empieza bien casaria entera
    // y se publicaria en el `Context`, y de ahi a Loki durante 90 dias.
    'valida y despues basura' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01AAAAAAAAAAAA'],
    'basura y despues valida' => ['ZZ00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'con salto de linea al final' => ["00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\n"],
])->group('RL-08', 'RS-02');

it('no da por bueno un identificador a ceros, que es el del span inerte', function (): void {
    // Escribirlo seria peor que no escribir nada: parece un identificador y nadie
    // lo buscaria dos veces.
    expect(TraceparentHeader::traceIdOf('00-00000000000000000000000000000000-00f067aa0ba902b7-01'))->toBeNull()
        ->and(TraceparentHeader::significant('00000000000000000000000000000000'))->toBeNull()
        // Y tampoco ceros con espacios, que es como recortaba una de las copias.
        ->and(TraceparentHeader::significant('0 0 0'))->toBeNull()
        ->and(TraceparentHeader::significant(''))->toBeNull()
        ->and(TraceparentHeader::significant(null))->toBeNull();
})->group('RL-08');

it('el patron del armazon es el mismo objeto, no una segunda copia', function (): void {
    // La garantia de que corregir el patron en un sitio lo corrige en todos. Si
    // alguien vuelve a escribir la regex a mano en `TraceContext`, esto falla.
    expect(TraceContext::TRACEPARENT_PATTERN)->toBe(TraceparentHeader::PATTERN)
        ->and(TraceContext::TRACEPARENT_KEY)->toBe(TraceparentHeader::NAME);
})->group('RL-08');
