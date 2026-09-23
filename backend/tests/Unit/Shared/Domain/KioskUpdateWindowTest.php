<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\KioskUpdateWindow;

/*
 * La ventana de actualizacion del quiosco (**RF-KI-07**, tarea 3.12).
 *
 * ## Que se defiende aqui, que es solo la mitad servidor
 *
 * El servidor **no decide** si la tablet se actualiza: transporta la franja por
 * el latido y la decision —franja abierta, cola vacia y sin escaneos recientes—
 * la toma el quiosco, que es el unico que sabe las otras dos cosas. Lo que este
 * objeto garantiza es que lo transportado tiene forma de franja y significa
 * siempre lo mismo, para que el servidor y la tablet no puedan leer dos cosas
 * distintas del mismo ajuste.
 */

it('parte la franja en sus dos extremos', function (): void {
    $window = KioskUpdateWindow::fromRange('03:00-05:00');

    expect($window->start)->toBe('03:00')
        ->and($window->end)->toBe('05:00')
        ->and($window->toRange())->toBe('03:00-05:00');
})->group('RF-KI-07');

it('admite una franja que cruza la medianoche', function (): void {
    // Un hotel con turno de noche tiene su hueco tranquilo a caballo de las
    // doce, y prohibirlo obligaria a declarar dos ventanas o a no tener ninguna.
    $window = KioskUpdateWindow::fromRange('23:00-02:00');

    expect($window->start)->toBe('23:00')
        ->and($window->end)->toBe('02:00');
})->group('RF-KI-07');

it('admite los dos extremos iguales, que significa «nunca sola»', function (): void {
    // Es una configuracion legitima —«mis tablets las actualizo yo»— y no un
    // estado invalido. Rechazarla habria obligado al adaptador a lanzar en el
    // camino de fichaje, que es justo lo que la regla dura 19 prohibe.
    $window = KioskUpdateWindow::fromRange('03:00-03:00');

    expect($window->start)->toBe($window->end);
})->group('RF-KI-07');

it('acepta los bordes exactos del reloj de 24 horas', function (string $range): void {
    expect(KioskUpdateWindow::fromRange($range)->toRange())->toBe($range);
})->with([
    'medianoche a medianoche' => ['00:00-00:00'],
    'el ultimo minuto del dia' => ['23:59-00:01'],
    'una franja de un minuto' => ['04:00-04:01'],
])->group('RF-KI-07');

it('rechaza lo que no es una franja HH:MM-HH:MM', function (string $range): void {
    expect(fn (): KioskUpdateWindow => KioskUpdateWindow::fromRange($range))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'con una hora que no existe' => ['24:00-05:00'],
    'con un minuto que no existe' => ['03:60-05:00'],
    'sin ceros a la izquierda' => ['3:00-5:00'],
    'con segundos' => ['03:00:00-05:00:00'],
    'con un solo extremo' => ['03:00'],
    'con texto' => ['de tres a cinco'],
    'vacio' => [''],
])->group('RF-KI-07');
