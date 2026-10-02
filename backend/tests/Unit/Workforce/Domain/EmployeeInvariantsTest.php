<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * Las invariantes de la ficha del empleado que el constructor hace cumplir, y lo
 * que cada cambio conserva (RF-GP-01, RF-GP-03, RN-14).
 *
 * La ficha es lo que cruza la frontera entre los casos de uso y la base: una
 * ficha sin nombre, sin centro o con un departamento imposible no puede llegar
 * a escribirse, y una baja no se reabre por la puerta de la suspension.
 */

/**
 * Una ficha valida, con los campos que el caso necesita cambiados.
 */
function fichaParaInvariantes(
    string $uuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    string $firstName = 'Youssef',
    string $lastName = 'Amrani',
    ?string $email = 'youssef@hotel.example',
    int $siteId = 1,
    ?int $departmentId = 3,
    EmploymentStatus $status = EmploymentStatus::ACTIVE,
    string $hiredAt = '2026-01-15',
    ?string $terminatedAt = null,
    string $locale = 'es',
    bool $teleworking = false,
): Employee {
    return new Employee(
        uuid: $uuid,
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: $firstName,
        lastName: $lastName,
        email: $email,
        siteId: $siteId,
        departmentId: $departmentId,
        status: $status,
        hiredAt: new DateTimeImmutable($hiredAt),
        terminatedAt: $terminatedAt === null ? null : new DateTimeImmutable($terminatedAt),
        locale: $locale,
        teleworking: $teleworking,
    );
}

it('rechaza una ficha sin identidad completa', function (array $ficha): void {
    expect(fn (): Employee => fichaParaInvariantes(...$ficha))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin UUID' => [['uuid' => '']],
    'sin nombre' => [['firstName' => '']],
    'con el nombre en blanco' => [['firstName' => '   ']],
    'sin apellidos' => [['lastName' => '']],
    'con los apellidos en blanco' => [['lastName' => '   ']],
    'sin idioma' => [['locale' => '']],
])->group('RF-GP-01');

it('rechaza una ficha sin centro o con un departamento imposible', function (array $ficha): void {
    expect(fn (): Employee => fichaParaInvariantes(...$ficha))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'centro 0' => [['siteId' => 0]],
    'departamento 0' => [['departmentId' => 0]],
    'departamento negativo' => [['departmentId' => -1]],
])->group('RF-GP-01');

it('admite el primer identificador de centro y de departamento, y la ficha sin departamento', function (?int $departmentId): void {
    $ficha = fichaParaInvariantes(siteId: 1, departmentId: $departmentId);

    expect($ficha->siteId)->toBe(1)
        ->and($ficha->departmentId)->toBe($departmentId);
})->with([
    'departamento 1' => [1],
    'sin departamento' => [null],
])->group('RF-GP-01');

it('admite una baja el mismo dia del alta aunque el alta tenga hora', function (): void {
    // Las dos son fechas civiles: el alta a las 18:30 no deja fuera un cese
    // registrado para ese mismo dia.
    $ficha = fichaParaInvariantes(
        status: EmploymentStatus::TERMINATED,
        hiredAt: '2026-01-15 18:30:00',
        terminatedAt: '2026-01-15 00:00:00',
    );

    expect($ficha->terminatedAt?->format('Y-m-d'))->toBe('2026-01-15');
})->group('RF-GP-03');

it('no deja suspender ni reincorporar a quien esta de baja', function (string $cambio): void {
    // Reincorporar una baja seria deshacerla sin fecha ni asiento (RN-14).
    $baja = fichaParaInvariantes(status: EmploymentStatus::TERMINATED, terminatedAt: '2026-08-31');

    expect(fn (): Employee => $baja->{$cambio}())
        ->toThrow(EmployeeAlreadyTerminated::class);
})->with([
    'suspender' => ['suspend'],
    'reincorporar' => ['reinstate'],
])->group('RF-GP-03', 'RN-14');

it('aplica el nombre, los apellidos y el idioma que se piden', function (): void {
    $cambiada = fichaParaInvariantes()->updateProfile(firstName: 'Lucia', lastName: 'Ferrer', locale: 'en');

    expect($cambiada->firstName)->toBe('Lucia')
        ->and($cambiada->lastName)->toBe('Ferrer')
        ->and($cambiada->locale)->toBe('en');
})->group('RF-GP-01');

it('conserva todo lo que el cambio de ficha no menciona', function (): void {
    $antes = fichaParaInvariantes(teleworking: true);

    $despues = $antes->updateProfile(firstName: 'Lucia');

    expect([
        $despues->uuid,
        $despues->code->value,
        $despues->lastName,
        $despues->email,
        $despues->siteId,
        $despues->departmentId,
        $despues->status,
        $despues->hiredAt->format('Y-m-d'),
        $despues->terminatedAt,
        $despues->locale,
        $despues->teleworking,
    ])->toBe([
        '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        'E7QK2MXPR',
        'Amrani',
        'youssef@hotel.example',
        1,
        3,
        EmploymentStatus::ACTIVE,
        '2026-01-15',
        null,
        'es',
        true,
    ]);
})->group('RF-GP-01');

it('muestra la inicial del apellido sin espacios y respetando los caracteres multibyte', function (string $firstName, string $lastName, string $nombreVisible): void {
    expect(fichaParaInvariantes(firstName: $firstName, lastName: $lastName)->displayName())->toBe($nombreVisible);
})->with([
    'tilde minuscula' => ['Ana', 'álvarez', 'Ana Á.'],
    'eñe con espacios delante' => ['  Ana  ', '  ñúñez', 'Ana Ñ.'],
])->group('RS-04', 'RF-AT-05');
