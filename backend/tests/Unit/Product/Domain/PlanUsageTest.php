<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\PlanUsage;

/*
 * Contratado frente a real (ADR-028, RF-PD-04, H5): ninguna de las dos cifras
 * cuenta en negativo. Sin licencia, lo contratado es nulo, no cero.
 */

it('admite el uso sin licencia, en el plan y por encima', function (?int $contratado, int $real, int $exceso): void {
    expect(new PlanUsage(PlanLimit::cases()[0], $contratado, $real)->excess())->toBe($exceso);
})->with([
    'sin licencia' => [null, 12, 0],
    'instalacion vacia' => [0, 0, 0],
    'dentro del plan' => [100, 99, 0],
    'por encima' => [100, 101, 1],
])->group('RF-PD-04');

it('rechaza un uso que cuenta en negativo', function (?int $contratado, int $real): void {
    expect(static fn (): PlanUsage => new PlanUsage(PlanLimit::cases()[0], $contratado, $real))->toThrow(InvalidArgumentException::class);
})->with([
    'real negativo' => [100, -1],
    'real negativo sin licencia' => [null, -1],
    'contratado negativo' => [-1, 0],
])->group('RF-PD-04');
