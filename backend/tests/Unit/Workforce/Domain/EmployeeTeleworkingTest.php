<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * La marca de teletrabajo de la ficha (RF-GP-01, decision comercial de la
 * 2.1.0). Informativa: nace en `false`, la cambia `updateProfile()` como
 * cualquier otro dato de la ficha, sobrevive a suspension, reincorporacion y
 * baja, y no cambia quien puede fichar (RN-14).
 */

function teletrabajoFicha(bool $teleworking = false, EmploymentStatus $status = EmploymentStatus::ACTIVE): Employee
{
    return new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        siteId: 1,
        departmentId: 3,
        status: $status,
        hiredAt: new DateTimeImmutable('2026-01-15'),
        terminatedAt: $status === EmploymentStatus::TERMINATED ? new DateTimeImmutable('2026-08-31') : null,
        locale: 'es',
        teleworking: $teleworking,
    );
}

it('nace sin teletrabajo si nadie lo dice', function (): void {
    $employee = Employee::hire(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::generate(),
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        siteId: 1,
        departmentId: null,
        hiredAt: new DateTimeImmutable('2026-08-14'),
        locale: 'es',
    );

    expect($employee->teleworking)->toBeFalse();
})->group('RF-GP-01');

it('nace con teletrabajo si el alta lo marca', function (): void {
    $employee = Employee::hire(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::generate(),
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        siteId: 1,
        departmentId: null,
        hiredAt: new DateTimeImmutable('2026-08-14'),
        locale: 'es',
        teleworking: true,
    );

    expect($employee->teleworking)->toBeTrue()
        ->and($employee->status)->toBe(EmploymentStatus::ACTIVE);
})->group('RF-GP-01');

it('marca y desmarca el teletrabajo sin tocar nada mas de la ficha', function (): void {
    $before = teletrabajoFicha();
    $marked = $before->updateProfile(teleworking: true);
    $unmarked = $marked->updateProfile(teleworking: false);

    expect($marked->teleworking)->toBeTrue()
        ->and($unmarked->teleworking)->toBeFalse()
        // Inmutable: el original no cambia.
        ->and($before->teleworking)->toBeFalse()
        ->and($marked->departmentId)->toBe($before->departmentId)
        ->and($marked->locale)->toBe($before->locale)
        ->and($marked->email)->toBe($before->email)
        ->and($marked->status)->toBe($before->status);
})->group('RF-GP-01');

it('no toca el teletrabajo si el cambio no lo menciona', function (): void {
    expect(teletrabajoFicha(true)->updateProfile(firstName: 'Ana')->teleworking)->toBeTrue()
        ->and(teletrabajoFicha(false)->updateProfile(locale: 'en')->teleworking)->toBeFalse();
})->group('RF-GP-01');

it('conserva la marca al suspender, reincorporar y dar de baja', function (): void {
    $employee = teletrabajoFicha(true);

    expect($employee->suspend()->teleworking)->toBeTrue()
        ->and($employee->suspend()->reinstate()->teleworking)->toBeTrue()
        ->and($employee->offboard(new DateTimeImmutable('2026-08-31'))->teleworking)->toBeTrue();
})->group('RF-GP-01', 'RF-GP-03');

it('no deja cambiar la marca de una ficha dada de baja', function (): void {
    // Como cualquier otro dato de la ficha: una baja no se edita (RF-GP-03).
    expect(fn (): Employee => teletrabajoFicha(false, EmploymentStatus::TERMINATED)->updateProfile(teleworking: true))
        ->toThrow(EmployeeAlreadyTerminated::class);
})->group('RF-GP-01', 'RF-GP-03');

it('no cambia quien puede fichar: la marca es informativa', function (bool $teleworking): void {
    // RN-14 decide con el estado y solo con el estado. Dos fichas identicas
    // salvo la marca responden lo mismo en cada estado.
    foreach (EmploymentStatus::cases() as $status) {
        expect(teletrabajoFicha($teleworking, $status)->canClock())
            ->toBe(teletrabajoFicha(! $teleworking, $status)->canClock());
    }

    expect(teletrabajoFicha($teleworking)->displayName())->toBe(teletrabajoFicha(! $teleworking)->displayName());
})->with([true, false])->group('RF-GP-01', 'RN-14');
