<?php

declare(strict_types=1);

use Tests\MutationCanary\MutationCanary;

/*
 * Las dos pruebas del canario de la mutacion. Su fuerza esta medida a
 * proposito y no se toca: la primera mata todos los mutantes de `next()` y la
 * segunda no mata ninguno de los de `isPositive()`. `make mutate-canary`
 * exige exactamente ese reparto; la explicacion completa esta en
 * {@see MutationCanary}.
 */

it('suma uno al valor', function (): void {
    expect(MutationCanary::next(41))->toBe(42);
})->group('RQ-10');

it('responde con un booleano', function (): void {
    expect(MutationCanary::isPositive(5))->toBeBool();
})->group('RQ-10');
