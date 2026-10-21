<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * La ficha minimizada por retencion como objeto de dominio (ADR-060, tarea
 * 11.3 A; RL-11): sin nombre ni apellidos, siempre de baja y sin poder
 * modificarse. Fuera de ese caso, el nombre sigue siendo obligatorio.
 */

function fichaMinimizadaDe(EmploymentStatus $status = EmploymentStatus::TERMINATED, string $firstName = '', ?DateTimeImmutable $anonymizedAt = null): Employee
{
    return new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('0199f0c21f4a7c3e9b214d5e6f7a8b90'),
        firstName: $firstName,
        lastName: '',
        email: null,
        siteId: 1,
        departmentId: 3,
        status: $status,
        hiredAt: new DateTimeImmutable('2010-01-01'),
        terminatedAt: $status === EmploymentStatus::TERMINATED ? new DateTimeImmutable('2015-06-30') : null,
        locale: 'es',
        anonymizedAt: $anonymizedAt ?? new DateTimeImmutable('2026-10-21T04:00:00Z'),
    );
}

it('admite una ficha de baja minimizada, sin nombre ni apellidos', function (): void {
    $ficha = fichaMinimizadaDe();

    expect($ficha->firstName)->toBe('')
        ->and($ficha->lastName)->toBe('')
        ->and($ficha->anonymizedAt?->format(DATE_ATOM))->toBe('2026-10-21T04:00:00+00:00')
        ->and($ficha->code->value)->toBe('0199F0C21F4A7C3E9B214D5E6F7A8B90')
        ->and($ficha->canClock())->toBeFalse();
})->group('RL-11');

it('no admite minimizada la ficha de alguien de alta o suspendido', function (EmploymentStatus $status): void {
    expect(fn (): Employee => fichaMinimizadaDe($status))
        ->toThrow(InvalidArgumentException::class, 'Solo se minimiza la ficha de una persona dada de baja.');
})->with([
    'de alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RL-11');

it('sigue exigiendo nombre a una ficha que no esta minimizada', function (): void {
    expect(fn (): Employee => new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: '',
        lastName: 'Ferrer',
        email: null,
        siteId: 1,
        departmentId: null,
        status: EmploymentStatus::TERMINATED,
        hiredAt: new DateTimeImmutable('2010-01-01'),
        terminatedAt: new DateTimeImmutable('2015-06-30'),
        locale: 'es',
    ))->toThrow(InvalidArgumentException::class, 'Un empleado necesita nombre y apellidos.');
})->group('RL-11', 'RF-GP-01');

it('una ficha minimizada no se modifica ni se reincorpora', function (): void {
    $ficha = fichaMinimizadaDe();

    expect(fn (): Employee => $ficha->updateProfile(firstName: 'De vuelta'))
        ->toThrow(EmployeeAlreadyTerminated::class)
        ->and(fn (): Employee => $ficha->reinstate())->toThrow(EmployeeAlreadyTerminated::class)
        ->and(fn (): Employee => $ficha->suspend())->toThrow(EmployeeAlreadyTerminated::class);
})->group('RL-11', 'RF-GP-03');
