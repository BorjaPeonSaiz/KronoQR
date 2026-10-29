<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
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

/*
 * La fecha de cese frente a «hoy» y frente al alta (RN-14). Las tres son fechas
 * civiles: se comparan por su `Y-m-d`, nunca como instantes, y el huso del
 * objeto no cambia el resultado. Desde la 2.3.0 una fecha de hoy o posterior
 * programa la baja; la tabla entera, con «Aplicar ya», esta en
 * `ScheduledOffboardingTransitionsTest`.
 */

/** La excepcion que lanza `$baja`; si no lanza ninguna, la prueba falla. */
function rechazoDeLaBaja(Closure $baja): InvalidEmploymentPeriod
{
    try {
        $baja();
    } catch (InvalidEmploymentPeriod $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba InvalidEmploymentPeriod y no se lanzo nada.');
}

function fechaCivilDeLaBaja(string $value, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone($zone));
}

it('da de baja en el acto con cualquier dia pasado hasta el alta', function (string $cese): void {
    $baja = fichaParaInvariantes()->offboard(fechaCivilDeLaBaja($cese), fechaCivilDeLaBaja('2026-10-02'));

    expect($baja->status)->toBe(EmploymentStatus::TERMINATED)
        ->and($baja->terminatedAt?->format('Y-m-d H:i:s'))->toBe($cese.' 00:00:00');
})->with([
    'ayer' => ['2026-10-01'],
    'el dia del alta' => ['2026-01-15'],
])->group('RN-14', 'RF-GP-03');

it('compara la fecha civil de hoy en su zona y no el instante', function (string $hoy, string $zona, string $cese, EmploymentStatus $estado): void {
    $baja = fichaParaInvariantes()->offboard(fechaCivilDeLaBaja($cese), fechaCivilDeLaBaja($hoy, $zona));

    expect($baja->status)->toBe($estado)
        ->and($baja->terminatedAt?->format('Y-m-d'))->toBe(substr($cese, 0, 10));
})->with([
    // 2026-10-02T23:30Z: en Canarias ya es el dia 3, y el 2 ya es ayer.
    'Canarias a las 00:30 del dia 3, cese el 2' => ['2026-10-03 00:30:00', 'Atlantic/Canary', '2026-10-02', EmploymentStatus::TERMINATED],
    // 2026-10-02T21:30Z: en Madrid aun es el dia 2, y el 2 es hoy.
    'Madrid a las 23:30 del dia 2, cese el 2' => ['2026-10-02 23:30:00', 'Europe/Madrid', '2026-10-02', EmploymentStatus::ACTIVE],
    // La hora del cese no cuenta: hoy a las 18:00 sigue siendo hoy a las 09:00.
    'cese de hoy con hora posterior a la de hoy' => ['2026-10-02 09:00:00', 'UTC', '2026-10-02 18:00:00', EmploymentStatus::ACTIVE],
])->group('RN-14', 'RF-GP-03');

it('guarda la fecha de cese sin hora y en su propia fecha civil', function (): void {
    $baja = fichaParaInvariantes()->offboard(
        fechaCivilDeLaBaja('2026-10-02 23:30:00', 'Europe/Madrid'),
        fechaCivilDeLaBaja('2026-10-02 23:45:00', 'Europe/Madrid'),
        applyNow: true,
    );

    expect($baja->terminatedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-02 00:00:00');
})->group('RF-GP-03');

it('rechaza un cese anterior al alta con su clave, sus fechas y su mensaje tecnico', function (bool $aplicarYa): void {
    $rechazo = rechazoDeLaBaja(fn (): Employee => fichaParaInvariantes()->offboard(
        fechaCivilDeLaBaja('2026-01-14'),
        fechaCivilDeLaBaja('2026-10-02'),
        $aplicarYa,
    ));

    expect($rechazo->translationKey)->toBe('employees.errors.termination_before_hiring')
        ->and($rechazo->parameters)->toBe(['terminated_on' => '2026-01-14', 'hired_on' => '2026-01-15'])
        ->and($rechazo->getMessage())->toBe('The termination date 2026-01-14 is earlier than the hiring date 2026-01-15 (RF-GP-03).');
})->with(['sin aplicar ya' => [false], 'con aplicar ya' => [true]])->group('RF-GP-03', 'RN-14');

it('con el alta todavia por llegar da de baja sin efectos en la fecha del alta, con o sin aplicar ya', function (bool $aplicarYa): void {
    $baja = fichaParaInvariantes(hiredAt: '2026-10-15')
        ->offboard(fechaCivilDeLaBaja('2026-10-15'), fechaCivilDeLaBaja('2026-10-02'), $aplicarYa);

    expect($baja->status)->toBe(EmploymentStatus::TERMINATED)
        ->and($baja->terminatedAt?->format('Y-m-d'))->toBe('2026-10-15');
})->with(['sin aplicar ya' => [false], 'con aplicar ya' => [true]])->group('RN-14', 'RF-GP-03');

it('con el alta todavia por llegar rechaza un cese anterior al alta y programa uno posterior', function (): void {
    $ficha = fichaParaInvariantes(hiredAt: '2026-10-15');
    $hoy = fechaCivilDeLaBaja('2026-10-02');

    expect(rechazoDeLaBaja(fn (): Employee => $ficha->offboard(fechaCivilDeLaBaja('2026-10-02'), $hoy))->translationKey)
        ->toBe('employees.errors.termination_before_hiring')
        ->and(rechazoDeLaBaja(fn (): Employee => $ficha->offboard(fechaCivilDeLaBaja('2026-10-14'), $hoy))->translationKey)
        ->toBe('employees.errors.termination_before_hiring')
        ->and($ficha->offboard(fechaCivilDeLaBaja('2026-10-20'), $hoy)->isOffboardingScheduled())->toBeTrue();
})->group('RN-14', 'RF-GP-03');

it('con el alta hoy y el cese hoy programa la baja, como a cualquiera', function (): void {
    $ficha = fichaParaInvariantes(hiredAt: '2026-10-02');
    $hoy = fechaCivilDeLaBaja('2026-10-02');

    expect($ficha->offboard(fechaCivilDeLaBaja('2026-10-02'), $hoy)->isOffboardingScheduled())->toBeTrue()
        ->and(rechazoDeLaBaja(fn (): Employee => $ficha->offboard(fechaCivilDeLaBaja('2026-10-01'), $hoy))->translationKey)
        ->toBe('employees.errors.termination_before_hiring');
})->group('RN-14', 'RF-GP-03');

it('una baja ya registrada no se repite aunque la fecha nueva tampoco valdria', function (): void {
    $baja = fichaParaInvariantes(status: EmploymentStatus::TERMINATED, terminatedAt: '2026-08-31');

    expect(fn (): Employee => $baja->offboard(fechaCivilDeLaBaja('2026-12-31'), fechaCivilDeLaBaja('2026-10-02')))
        ->toThrow(EmployeeAlreadyTerminated::class);
})->group('RF-GP-03');

it('compara el cese con el alta por su fecha civil aunque esten en zonas distintas', function (): void {
    // 00:30 en Madrid en enero son las 23:30 UTC del dia anterior: comparadas
    // como instantes, el cese quedaria antes del alta.
    $ficha = new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        siteId: 1,
        departmentId: null,
        status: EmploymentStatus::TERMINATED,
        hiredAt: fechaCivilDeLaBaja('2026-01-15'),
        terminatedAt: fechaCivilDeLaBaja('2026-01-15 00:30:00', 'Europe/Madrid'),
        locale: 'es',
    );

    expect($ficha->terminatedAt?->format('Y-m-d'))->toBe('2026-01-15');
})->group('RF-GP-03');
