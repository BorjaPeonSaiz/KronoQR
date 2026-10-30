<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\EmployeeRecordMinimised;
use App\Modules\Workforce\Domain\Exception\WorkforceConflict;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use PHPUnit\Framework\Assert;

/*
 * La ficha minimizada por retencion como objeto de dominio (ADR-060, tarea
 * 11.3 A; RL-11): sin nombre, apellidos ni correo, siempre de baja, nunca antes
 * de su cese, sin poder modificarse y sin admitir contratos ni ausencias. Fuera
 * de ese caso, el nombre sigue siendo obligatorio.
 */

function fichaMinimizadaDe(
    EmploymentStatus $status = EmploymentStatus::TERMINATED,
    string $firstName = '',
    ?DateTimeImmutable $anonymizedAt = null,
    string $lastName = '',
    ?string $email = null,
): Employee {
    return new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('0199f0c21f4a7c3e9b214d5e6f7a8b90'),
        firstName: $firstName,
        lastName: $lastName,
        email: $email,
        siteId: 1,
        departmentId: 3,
        status: $status,
        hiredAt: new DateTimeImmutable('2010-01-01'),
        terminatedAt: $status === EmploymentStatus::TERMINATED ? new DateTimeImmutable('2015-06-30') : null,
        locale: 'es',
        anonymizedAt: $anonymizedAt ?? new DateTimeImmutable('2026-10-21T04:00:00Z'),
    );
}

function fichaEntera(): Employee
{
    return new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: 'Lucia',
        lastName: 'Ferrer',
        email: null,
        siteId: 1,
        departmentId: null,
        status: EmploymentStatus::TERMINATED,
        hiredAt: new DateTimeImmutable('2010-01-01'),
        terminatedAt: new DateTimeImmutable('2015-06-30'),
        locale: 'es',
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

it('no admite minimizada una ficha que conserva nombre, apellidos o correo, como el CHECK', function (string $firstName, string $lastName, ?string $email): void {
    expect(fn (): Employee => fichaMinimizadaDe(firstName: $firstName, lastName: $lastName, email: $email))
        ->toThrow(InvalidArgumentException::class, 'Una ficha minimizada no conserva nombre, apellidos ni correo (ADR-060).');
})->with([
    'con nombre' => ['Lucia', '', null],
    'con apellidos' => ['', 'Ferrer', null],
    'con correo' => ['', '', 'lucia@hotel.example'],
])->group('RL-11');

it('no admite minimizada una ficha antes de su fecha de cese, y si el mismo dia', function (): void {
    expect(fn (): Employee => fichaMinimizadaDe(anonymizedAt: new DateTimeImmutable('2015-06-29T23:59:59Z')))
        ->toThrow(InvalidArgumentException::class, 'Una ficha no se minimiza antes de su fecha de cese (ADR-060).')
        ->and(fichaMinimizadaDe(anonymizedAt: new DateTimeImmutable('2015-06-30T00:00:00Z'))->anonymizedAt?->format('Y-m-d'))
        ->toBe('2015-06-30');
})->group('RL-11');

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

it('una ficha minimizada no admite contratos ni ausencias, con un conflicto traducible', function (): void {
    try {
        fichaMinimizadaDe()->assertAcceptsPersonnelRecords();
        Assert::fail('Una ficha minimizada ha admitido un contrato o una ausencia.');
    } catch (EmployeeRecordMinimised $minimised) {
        expect($minimised)->toBeInstanceOf(WorkforceConflict::class)
            ->and($minimised->translationKey)->toBe('employees.errors.record_minimised')
            ->and($minimised->parameters)->toBe(['anonymized_on' => '2026-10-21'])
            ->and($minimised->getMessage())->toBe(
                'The personnel record 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 was minimised by retention on 2026-10-21 '
                .'(ADR-060): it takes no new contracts, absences, rehiring nor termination date corrections.',
            );
    }
})->group('RL-11', 'RF-GP-02', 'RF-GP-04');

it('una ficha entera, aunque este de baja, si los admite', function (): void {
    fichaEntera()->assertAcceptsPersonnelRecords();

    expect(fichaEntera()->anonymizedAt)->toBeNull();
})->group('RL-11', 'RF-GP-02', 'RF-GP-04');
