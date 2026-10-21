<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Event\DepartmentRenamed;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;

/*
 * Los eventos de la plantilla que acaban en `audit_log` llevan de cual a cual
 * (R4-SC-05, R7-RV-02): el traslado, el departamento de antes y el de despues;
 * el renombrado, el nombre anterior y el nuevo.
 */

it('dice que la persona cambio de departamento solo si los dos valores difieren', function (?int $antes, ?int $despues, bool $cambio): void {
    $event = new EmployeeProfileUpdated(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        changedFields: ['first_name'],
        previousDepartmentId: $antes,
        departmentId: $despues,
        occurredAt: new DateTimeImmutable('2026-09-14T10:00:00Z'),
    );

    expect($event->changedDepartment())->toBe($cambio)
        ->and($event->previousDepartmentId)->toBe($antes)
        ->and($event->departmentId)->toBe($despues)
        ->and($event->eventName())->toBe('workforce.employee_profile_updated')
        ->and($event->occurredAt()->format(DATE_ATOM))->toBe('2026-09-14T10:00:00+00:00');
})->with([
    'mismo departamento' => [3, 3, false],
    'sin departamento antes ni despues' => [null, null, false],
    'traslado' => [3, 4, true],
    'se le asigna uno' => [null, 4, true],
    'se le quita' => [3, null, true],
])->group('RF-GP-01', 'RF-ID-03', 'RL-04');

it('lleva el nombre anterior y el nuevo del departamento renombrado', function (): void {
    $event = new DepartmentRenamed(
        departmentId: 7,
        changedFields: ['name'],
        previousName: 'Lavanderia',
        name: 'Lavanderia y costura',
        occurredAt: new DateTimeImmutable('2026-09-14T10:00:00Z'),
    );

    expect($event->departmentId)->toBe(7)
        ->and($event->changedFields)->toBe(['name'])
        ->and($event->previousName)->toBe('Lavanderia')
        ->and($event->name)->toBe('Lavanderia y costura')
        ->and($event->eventName())->toBe('workforce.department_renamed')
        ->and($event->occurredAt()->format(DATE_ATOM))->toBe('2026-09-14T10:00:00+00:00');
})->group('RF-GP-02', 'RL-04');
