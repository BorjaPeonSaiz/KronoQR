<?php

declare(strict_types=1);

use Tests\Support\Time\Samples;

/*
 * La mediana de las pruebas de tiempo constante (RS-03): una sola para todas
 * desde la revision del bloque 11 de la 2.2.1.
 */

it('toma el valor central con un numero impar de muestras, sin importar el orden', function (): void {
    expect(Samples::median([9.0, 1.0, 5.0]))->toBe(5.0);
})->group('RS-03');

it('promedia los dos centrales con un numero par de muestras', function (): void {
    expect(Samples::median([4.0, 1.0, 10.0, 2.0]))->toBe(3.0);
})->group('RS-03');

it('no se deja arrastrar por una sola muestra lenta', function (): void {
    expect(Samples::median([150.0, 151.0, 149.0, 152.0, 4000.0]))->toBe(151.0);
})->group('RS-03');

it('devuelve la unica muestra cuando solo hay una', function (): void {
    expect(Samples::median([7.5]))->toBe(7.5);
})->group('RS-03');

it('se niega a dar una mediana sin muestras', function (): void {
    expect(fn (): float => Samples::median([]))->toThrow(InvalidArgumentException::class);
})->group('RS-03');
