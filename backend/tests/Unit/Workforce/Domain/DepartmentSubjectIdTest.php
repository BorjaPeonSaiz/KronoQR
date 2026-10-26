<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Event\DepartmentCreated;
use App\Modules\Workforce\Domain\Model\Department;

/*
 * El alta de un departamento apunta a su fila (RF-ID-10, RL-04, R1-AR-03): sin
 * fila, falla en lugar de dejar un asiento sobre el departamento 0.
 */

it('da el identificador del departamento guardado, y falla si no lo tiene', function (): void {
    expect(Department::create(1, 'Recepcion')->withId(5)->persistedId())->toBe(5)
        ->and(static fn (): int => Department::create(1, 'Recepcion')->persistedId())->toThrow(LogicException::class);
})->group('RF-ID-10', 'RL-04');

it('el hecho del alta rechaza un identificador que no es de una fila', function (int $id): void {
    $at = new DateTimeImmutable('2026-06-15T09:00:00Z');

    expect(static fn (): DepartmentCreated => new DepartmentCreated($id, 1, $at))->toThrow(InvalidArgumentException::class)
        ->and(new DepartmentCreated(1, 1, $at)->departmentId)->toBe(1);
})->with(['cero' => 0, 'negativo' => -1])->group('RF-ID-10', 'RL-04');
