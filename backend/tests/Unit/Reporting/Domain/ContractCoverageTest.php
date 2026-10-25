<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;

/*
 * Cuanto del informe se pudo comparar contra un contrato (RF-IN-03, H5): sin
 * cuentas negativas y sin personas sin dias ni dias sin persona.
 */

it('admite la cobertura completa y la que deja dias sin contrato', function (int $dias, int $personas, bool $completa): void {
    expect(new ContractCoverage($dias, $personas)->isComplete())->toBe($completa);
})->with([
    'completa' => [0, 0, true],
    'un dia de una persona' => [1, 1, false],
    'varios dias de dos personas' => [7, 2, false],
])->group('RF-IN-03');

it('rechaza una cobertura imposible', function (int $dias, int $personas): void {
    expect(static fn (): ContractCoverage => new ContractCoverage($dias, $personas))->toThrow(InvalidArgumentException::class);
})->with([
    'dias negativos' => [-1, 0],
    'personas negativas' => [0, -1],
    'mas personas que dias' => [1, 2],
    'personas sin dias' => [0, 1],
    'dias sin personas' => [3, 0],
])->group('RF-IN-03');
