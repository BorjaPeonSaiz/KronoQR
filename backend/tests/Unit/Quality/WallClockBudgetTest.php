<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Time\WallClockBudget;

/*
 * El presupuesto de reloj se afirma sin instrumentacion y se anuncia bajo
 * cobertura (CI-COB-01). Las dos ramas importan por igual: si la primera dejara
 * de afirmar, RNF-P-05 no se comprobaria en ningun sitio y nadie lo veria; si la
 * segunda volviera a afirmar, el job de cobertura fallaria por Xdebug y no por
 * el producto, que es justo lo que paso el 02-10-2026 con 5,88 s.
 *
 * El driver se pasa explicito: el resultado no puede depender de si esta misma
 * suite corre con Xdebug encendido (cobertura y mutacion) o apagado.
 */

it('falla cuando la medida supera el presupuesto y no hay cobertura', function (): void {
    expect(fn (): ?string => WallClockBudget::check(5.88, 5.0, 'RNF-P-05', null))
        ->toThrow(ExpectationFailedException::class, 'RNF-P-05: 5.880 s medidos, presupuesto 5.0 s.');
})->group('RNF-M-01');

it('falla tambien en el limite exacto del presupuesto', function (): void {
    expect(fn (): ?string => WallClockBudget::check(5.0, 5.0, 'RNF-P-05', null))
        ->toThrow(ExpectationFailedException::class);
})->group('RNF-M-01');

it('pasa sin anuncio cuando la medida cabe y no hay cobertura', function (): void {
    expect(WallClockBudget::check(4.99, 5.0, 'RNF-P-05', null))->toBeNull();
})->group('RNF-M-01');

it('anuncia sin afirmar cuando hay un driver de cobertura activo', function (string $driver): void {
    $anuncio = WallClockBudget::check(5.88, 5.0, 'RNF-P-05', $driver);

    expect($anuncio)->toBe(
        '[presupuesto-de-reloj] RNF-P-05 sin afirmar bajo cobertura ('.$driver.'): 5.880 s medidos, '
        .'presupuesto 5.0 s. Se afirma en la suite nocturna de rendimiento, sin cobertura (make test-performance).'
    );
})->with(['xdebug', 'pcov'])->group('RNF-M-01');

it('anuncia bajo cobertura aunque la medida quepa en el presupuesto', function (): void {
    expect(WallClockBudget::check(0.5, 5.0, 'RNF-P-05', 'xdebug'))
        ->toStartWith(WallClockBudget::NOTICE_PREFIX.' RNF-P-05 sin afirmar');
})->group('RNF-M-01');

it('falla en milisegundos y lo dice en milisegundos cuando no hay cobertura', function (): void {
    expect(fn (): ?string => WallClockBudget::check(0.62, 0.5, 'RF-PD-15', null, 'ms'))
        ->toThrow(ExpectationFailedException::class, 'RF-PD-15: 0.620 ms medidos, presupuesto 0.5 ms.');
})->group('RNF-M-01');

it('anuncia en milisegundos bajo cobertura', function (): void {
    expect(WallClockBudget::check(0.62, 0.5, 'RF-PD-15', 'xdebug', 'ms'))->toBe(
        '[presupuesto-de-reloj] RF-PD-15 sin afirmar bajo cobertura (xdebug): 0.620 ms medidos, '
        .'presupuesto 0.5 ms. Se afirma en la suite nocturna de rendimiento, sin cobertura (make test-performance).'
    );
})->group('RNF-M-01');

it('afirma la razon entre dos medidas sin cobertura y la anuncia con ella', function (): void {
    expect(fn (): ?string => WallClockBudget::check(1.5, 1.5, 'RS-03', null, 'x'))
        ->toThrow(ExpectationFailedException::class, 'RS-03: 1.500 x medidos, presupuesto 1.5 x.')
        ->and(WallClockBudget::check(42.0, 1.5, 'RS-03', 'xdebug', 'x'))
        ->toStartWith(WallClockBudget::NOTICE_PREFIX.' RS-03 sin afirmar bajo cobertura (xdebug): 42.000 x');
})->group('RNF-M-01', 'RS-03');

it('juzga la mediana y no la peor muestra', function (): void {
    // Un pico aislado de un runner compartido no decide el resultado (D4).
    expect(WallClockBudget::median([0.4, 9.1, 0.5, 0.6, 0.45]))->toBe(0.5);
})->group('RNF-M-01');

it('toma la media de las dos centrales con un numero par de muestras', function (): void {
    expect(WallClockBudget::median([4.0, 1.0, 3.0, 2.0]))->toBe(2.5);
})->group('RNF-M-01');

it('no inventa una mediana sin muestras', function (): void {
    expect(fn (): float => WallClockBudget::median([]))->toThrow(LogicException::class);
})->group('RNF-M-01');

it('descarta la pasada de calentamiento y devuelve las que se juzgan', function (): void {
    $pasada = 0;

    $muestras = WallClockBudget::samples(function () use (&$pasada): float {
        $pasada++;

        return (float) $pasada;
    });

    expect($muestras)->toBe([2.0, 3.0, 4.0, 5.0, 6.0]);
})->group('RNF-M-01');

it('cuenta la mediana y sus muestras en el mensaje del fallo', function (): void {
    expect(fn (): ?string => WallClockBudget::check(5.2, 5.0, 'RNF-P-05', null, 's', [5.1, 5.2, 5.3]))
        ->toThrow(ExpectationFailedException::class, 'RNF-P-05: 5.200 s medidos (mediana de 5.100, 5.200, 5.300), presupuesto 5.0 s.');
})->group('RNF-M-01');

it('no deja afirmar un presupuesto de reloj fuera de la suite de rendimiento', function (): void {
    expect(fn () => WallClockBudget::guardPerformanceGroup('RNF-P-05', ['RNF-P-05', 'RF-IN-01']))
        ->toThrow(LogicException::class, "->group('performance')");
})->group('RNF-M-01');

it('deja afirmarlo dentro de la suite de rendimiento', function (): void {
    WallClockBudget::guardPerformanceGroup('RNF-P-05', ['RNF-P-05', 'performance']);

    expect(WallClockBudget::GROUP)->toBe('performance');
})->group('RNF-M-01');

it('para la afirmacion de la prueba en curso si no esta en el grupo de rendimiento', function (): void {
    expect(fn () => WallClockBudget::expectMedianBelow([0.1], 5.0, 'RNF-P-05'))
        ->toThrow(LogicException::class, 'fuera de la suite de rendimiento');
})->group('RNF-M-01');
