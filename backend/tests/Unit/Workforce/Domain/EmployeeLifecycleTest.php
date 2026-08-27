<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * Alta y baja de empleado (RF-GP-01, RF-GP-03, RN-14).
 *
 * La regla que estas pruebas protegen es la regla dura 5: **nada se borra**. La
 * baja es un cambio de estado con fecha, y el objeto no ofrece ninguna via para
 * hacerla de otra forma.
 */

function employeeUnderTest(
    EmploymentStatus $status = EmploymentStatus::ACTIVE,
    ?string $terminatedAt = null,
): Employee {
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
        terminatedAt: $terminatedAt === null ? null : new DateTimeImmutable($terminatedAt),
        locale: 'es',
    );
}

it('da de alta a alguien sin direccion de correo', function (): void {
    // Regla dura 12: el producto no depende del correo del empleado. Ninguna
    // comprobacion del modelo lo exige.
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

    expect($employee->email)->toBeNull()
        ->and($employee->status)->toBe(EmploymentStatus::ACTIVE)
        ->and($employee->terminatedAt)->toBeNull();
})->group('RF-GP-01');

it('da de baja cambiando el estado y fijando la fecha de cese', function (): void {
    $terminated = employeeUnderTest()->offboard(new DateTimeImmutable('2026-08-31'));

    expect($terminated->status)->toBe(EmploymentStatus::TERMINATED)
        ->and($terminated->terminatedAt?->format('Y-m-d'))->toBe('2026-08-31')
        // Lo que NO cambia: la baja conserva todo lo demas. Es lo que hace que
        // el historial siga siendo legible cuatro anos despues (RL-02).
        ->and($terminated->uuid)->toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
        ->and($terminated->code->value)->toBe('E7QK2MXPR')
        ->and($terminated->hiredAt->format('Y-m-d'))->toBe('2026-01-15');
})->group('RF-GP-03');

it('deja de admitir fichajes en cuanto esta de baja', function (): void {
    // RN-14: solo el activo ficha.
    expect(employeeUnderTest()->canClock())->toBeTrue()
        ->and(employeeUnderTest()->offboard(new DateTimeImmutable('2026-08-31'))->canClock())->toBeFalse()
        ->and(employeeUnderTest()->suspend()->canClock())->toBeFalse();
})->group('RN-14', 'RF-GP-03');

it('no repite una baja ya registrada', function (): void {
    // Repetirla reescribiria la fecha de cese, que es desde la que cuenta la
    // retencion de RL-02. Corregir esa fecha exige una correccion trazada
    // (RN-13), no un segundo POST.
    $terminated = employeeUnderTest()->offboard(new DateTimeImmutable('2026-08-31'));

    expect(fn () => $terminated->offboard(new DateTimeImmutable('2026-09-30')))
        ->toThrow(EmployeeAlreadyTerminated::class);
})->group('RF-GP-03');

it('rechaza una fecha de cese anterior a la de alta', function (): void {
    expect(fn () => employeeUnderTest()->offboard(new DateTimeImmutable('2025-12-31')))
        ->toThrow(InvalidEmploymentPeriod::class);
})->group('RF-GP-03');

it('admite el cese el mismo dia del alta', function (): void {
    // Un contrato de un dia existe. El limite se escribe explicito porque es
    // donde la comparacion se equivoca (§3.5, valores limite).
    $terminated = employeeUnderTest()->offboard(new DateTimeImmutable('2026-01-15'));

    expect($terminated->terminatedAt?->format('Y-m-d'))->toBe('2026-01-15');
})->group('RF-GP-03');

it('no permite modificar la ficha de quien ya esta de baja', function (): void {
    $terminated = employeeUnderTest()->offboard(new DateTimeImmutable('2026-08-31'));

    expect(fn () => $terminated->updateProfile(firstName: 'Otro'))
        ->toThrow(EmployeeAlreadyTerminated::class);
})->group('RF-GP-03');

it('no puede existir una baja sin fecha de cese', function (): void {
    // La misma regla que declara `employees_chk_terminated_has_date` en la base
    // de datos. Aqui esta para que el error llegue antes y con significado.
    expect(fn () => employeeUnderTest(EmploymentStatus::TERMINATED))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-GP-03');

it('distingue suspension de baja', function (): void {
    // Suspender no cierra el registro de nadie: no lleva fecha de cese y se
    // puede deshacer.
    $suspended = employeeUnderTest()->suspend();

    expect($suspended->status)->toBe(EmploymentStatus::SUSPENDED)
        ->and($suspended->terminatedAt)->toBeNull()
        ->and($suspended->reinstate()->status)->toBe(EmploymentStatus::ACTIVE);
})->group('RF-GP-01', 'RN-14');

it('borra el correo solo cuando se pide explicitamente', function (): void {
    // En un PATCH, «no enviado» y «enviado a null» son dos ordenes distintas.
    $withEmail = employeeUnderTest()->updateProfile(email: 'lucia@hotel.example', emailGiven: true);

    expect($withEmail->email)->toBe('lucia@hotel.example')
        ->and($withEmail->updateProfile(firstName: 'Lucia')->email)->toBe('lucia@hotel.example')
        ->and($withEmail->updateProfile(email: null, emailGiven: true)->email)->toBeNull();
})->group('RF-GP-01');

it('muestra el nombre en su forma minima', function (): void {
    // §7.3 y RF-AT-05: nombre de pila e inicial del primer apellido. Un token de
    // quiosco robado no debe permitir reconstruir la plantilla del hotel.
    expect(employeeUnderTest()->displayName())->toBe('Youssef A.');
})->group('RS-04', 'RF-AT-05');
