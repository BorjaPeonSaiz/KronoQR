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
        .'presupuesto 5.0 s. Se afirma en las suites sin instrumentacion (job ④ de la CI y local).'
    );
})->with(['xdebug', 'pcov'])->group('RNF-M-01');

it('anuncia bajo cobertura aunque la medida quepa en el presupuesto', function (): void {
    expect(WallClockBudget::check(0.5, 5.0, 'RNF-P-05', 'xdebug'))
        ->toStartWith(WallClockBudget::NOTICE_PREFIX.' RNF-P-05 sin afirmar');
})->group('RNF-M-01');
