<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/*
 * Las fechas de alta y de cese que la instantanea del empleado lleva al nucleo
 * (RN-14, 2.2.0). Las usa el alta manual de tramos para acotar las jornadas de
 * una persona de baja; el fichaje no las mira.
 *
 * Son fechas civiles `Y-m-d` que existen en el calendario, y el cese no puede
 * ser anterior al alta: es la misma regla que `employees_chk_terminated_after_hired`.
 */

function instantaneaConFechas(?string $hiredOn, ?string $terminatedOn, EmploymentStatus $status = EmploymentStatus::TERMINATED): EmployeeSnapshot
{
    return new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', $status, 1, null, $hiredOn, $terminatedOn);
}

it('no lleva fechas si no se le dan', function (): void {
    $snapshot = new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', EmploymentStatus::ACTIVE, 1);

    expect($snapshot->hiredOn)->toBeNull()
        ->and($snapshot->terminatedOn)->toBeNull();
})->group('RN-14');

it('conserva las fechas de alta y de cese tal cual', function (?string $hiredOn, ?string $terminatedOn): void {
    $snapshot = instantaneaConFechas($hiredOn, $terminatedOn);

    expect($snapshot->hiredOn)->toBe($hiredOn)
        ->and($snapshot->terminatedOn)->toBe($terminatedOn);
})->with([
    'alta y cese' => ['2026-03-01', '2026-09-30'],
    'cese el mismo dia del alta' => ['2026-03-01', '2026-03-01'],
    'solo alta' => ['2026-03-01', null],
    'solo cese' => [null, '2026-09-30'],
    'bisiesto' => ['2028-02-29', '2028-03-01'],
])->group('RN-14');

it('rechaza una fecha que no es civil Y-m-d o que no existe', function (?string $hiredOn, ?string $terminatedOn, string $campo): void {
    expect(fn (): EmployeeSnapshot => instantaneaConFechas($hiredOn, $terminatedOn))
        ->toThrow(InvalidArgumentException::class, 'EmployeeSnapshot::'.$campo.' tiene que ser una fecha civil Y-m-d valida.');
})->with([
    'alta que no existe' => ['2026-02-31', null, 'hiredOn'],
    'alta con barras' => ['2026/03/01', null, 'hiredOn'],
    'alta al reves' => ['01-03-2026', null, 'hiredOn'],
    'alta con hora' => ['2026-03-01 10:00:00', null, 'hiredOn'],
    'alta vacia' => ['', null, 'hiredOn'],
    'cese que no existe' => [null, '2026-09-31', 'terminatedOn'],
    'cese sin ceros' => [null, '2026-9-30', 'terminatedOn'],
    'cese vacio' => [null, '', 'terminatedOn'],
])->group('RN-14');

it('rechaza un cese anterior al alta', function (): void {
    expect(fn (): EmployeeSnapshot => instantaneaConFechas('2026-03-01', '2026-02-28'))
        ->toThrow(InvalidArgumentException::class, 'La fecha de cese de EmployeeSnapshot no puede ser anterior a la de alta.');
})->group('RN-14');
