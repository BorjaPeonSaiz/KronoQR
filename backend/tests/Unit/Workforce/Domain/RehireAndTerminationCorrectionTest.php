<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeNotTerminated;
use App\Modules\Workforce\Domain\Exception\EmployeeRecordMinimised;
use App\Modules\Workforce\Domain\Exception\InvalidRehireDate;
use App\Modules\Workforce\Domain\Exception\InvalidTerminationCorrection;
use App\Modules\Workforce\Domain\Exception\OffboardingNotEffective;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\EmploymentHistory;
use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * **LA READMISION Y LA CORRECCION DE LA FECHA DE CESE** (RN-27, ADR-062 §1, §6 y
 * §7).
 *
 * `Employee::rehire()`: alta el dia del cese (no), el dia siguiente (si), sin
 * baja (no), con la baja solo programada (no) y minimizada (no). La historia
 * laboral: sin solapes y solo el ultimo periodo abierto. La correccion en sus
 * tres limites —hoy, el alta del periodo y el alta del siguiente—, hacia una
 * fecha posterior y hacia una anterior. Fechas civiles; sin reloj.
 */

function fichaDeLaReadmision(
    EmploymentStatus $status = EmploymentStatus::TERMINATED,
    ?string $terminatedAt = '2026-10-31',
    ?string $anonymizedAt = null,
): Employee {
    $minimised = $anonymizedAt !== null;

    return new Employee(
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        code: EmployeeCode::fromString('E7QK2MXPR'),
        firstName: $minimised ? '' : 'Lucia',
        lastName: $minimised ? '' : 'Ferrer',
        email: null,
        siteId: 1,
        departmentId: 3,
        status: $status,
        hiredAt: new DateTimeImmutable('2026-05-01'),
        terminatedAt: $terminatedAt === null ? null : new DateTimeImmutable($terminatedAt),
        locale: 'es',
        anonymizedAt: $anonymizedAt === null ? null : new DateTimeImmutable($anonymizedAt),
    );
}

it('readmite el dia siguiente al cese en la misma ficha, sin cese y conservando lo demas', function (): void {
    $readmitida = fichaDeLaReadmision()->rehire(new DateTimeImmutable('2026-11-01 15:30'));

    expect($readmitida->uuid)->toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
        ->and($readmitida->code->value)->toBe('E7QK2MXPR')
        ->and($readmitida->status)->toBe(EmploymentStatus::ACTIVE)
        ->and($readmitida->hiredAt->format('Y-m-d H:i'))->toBe('2026-11-01 00:00')
        ->and($readmitida->terminatedAt)->toBeNull()
        ->and($readmitida->departmentId)->toBe(3)
        ->and($readmitida->locale)->toBe('es')
        ->and($readmitida->firstName)->toBe('Lucia');
})->group('RN-27');

it('cambia el departamento y el idioma solo si se dan', function (): void {
    $otro = fichaDeLaReadmision()->rehire(new DateTimeImmutable('2027-04-01'), 7, true, 'en');
    $sin = fichaDeLaReadmision()->rehire(new DateTimeImmutable('2027-04-01'), null, true);

    expect($otro->departmentId)->toBe(7)
        ->and($otro->locale)->toBe('en')
        ->and($sin->departmentId)->toBeNull();
})->group('RN-27');

it('no readmite con una fecha de alta igual o anterior al cese', function (string $alta): void {
    expect(fn (): Employee => fichaDeLaReadmision()->rehire(new DateTimeImmutable($alta)))
        ->toThrow(InvalidRehireDate::class);
})->with(['el dia del cese' => ['2026-10-31'], 'antes del cese' => ['2026-10-01']])->group('RN-27');

it('no readmite a quien no esta de baja, tampoco con la baja solo programada', function (?string $cese, EmploymentStatus $status): void {
    expect(fn (): Employee => fichaDeLaReadmision($status, $cese)->rehire(new DateTimeImmutable('2027-04-01')))
        ->toThrow(EmployeeNotTerminated::class);
})->with([
    'en alta' => [null, EmploymentStatus::ACTIVE],
    'suspendida' => [null, EmploymentStatus::SUSPENDED],
    'con la baja programada' => ['2026-10-31', EmploymentStatus::ACTIVE],
])->group('RN-27');

it('no readmite una ficha minimizada por retencion', function (): void {
    expect(fn (): Employee => fichaDeLaReadmision(anonymizedAt: '2031-01-15')->rehire(new DateTimeImmutable('2031-04-01')))
        ->toThrow(EmployeeRecordMinimised::class);
})->group('RN-27', 'RL-11');

it('corrige el cese de la ficha de baja y la deja de baja', function (): void {
    $corregida = fichaDeLaReadmision()->withCorrectedTermination(new DateTimeImmutable('2026-11-03'));

    expect($corregida->status)->toBe(EmploymentStatus::TERMINATED)
        ->and($corregida->terminatedAt?->format('Y-m-d'))->toBe('2026-11-03');
})->group('RN-27');

// --- La historia laboral ---------------------------------------------------------

function historiaDeLaReadmision(): EmploymentHistory
{
    return new EmploymentHistory([
        new EmploymentPeriod('2027-04-01', null, false, 'p2'),
        new EmploymentPeriod('2026-05-01', '2026-10-31', true, 'p1'),
    ]);
}

it('ordena los periodos y sabe cual es el en curso y cual sigue a cual', function (): void {
    $historia = historiaDeLaReadmision();
    $primero = $historia->find('p1');

    expect(array_map(static fn (EmploymentPeriod $p): ?string => $p->uuid, $historia->periods))->toBe(['p1', 'p2'])
        ->and($historia->current()?->uuid)->toBe('p2')
        ->and($primero)->not->toBeNull()
        ->and($primero instanceof EmploymentPeriod ? $historia->after($primero)?->uuid : null)->toBe('p2')
        ->and($primero instanceof EmploymentPeriod && $historia->isCurrent($primero))->toBeFalse()
        ->and($historia->find('nadie'))->toBeNull()
        ->and((new EmploymentHistory([]))->current())->toBeNull();
})->group('RN-27');

it('no admite periodos que se solapan, comparten dia o dejan uno abierto que no es el ultimo', function (array $periodos): void {
    /** @var list<EmploymentPeriod> $periodos */
    expect(fn (): EmploymentHistory => new EmploymentHistory($periodos))->toThrow(InvalidArgumentException::class);
})->with([
    'comparten el dia del cese' => [[new EmploymentPeriod('2026-05-01', '2026-10-31', true), new EmploymentPeriod('2026-10-31')]],
    'uno anterior sin baja efectiva' => [[new EmploymentPeriod('2026-05-01', '2026-10-31', false), new EmploymentPeriod('2026-11-05')]],
])->group('RN-27');

it('dice el estado de cada periodo y si una fecha cae dentro o en el hueco', function (): void {
    $cerrado = new EmploymentPeriod('2026-05-01', '2026-10-31', true);
    $siguiente = new EmploymentPeriod('2027-04-01');

    expect($cerrado->state())->toBe('terminated')
        ->and(new EmploymentPeriod('2026-05-01', '2026-10-31')->state())->toBe('scheduled')
        ->and($siguiente->state())->toBe('active')
        ->and($cerrado->covers('2026-05-01'))->toBeTrue()
        ->and($cerrado->covers('2026-10-31'))->toBeTrue()
        ->and($cerrado->covers('2026-11-01'))->toBeFalse()
        ->and($cerrado->covers('2026-04-30'))->toBeFalse()
        ->and($siguiente->covers('2030-01-01'))->toBeTrue()
        ->and($cerrado->gapBefore($siguiente, '2027-01-15'))->toBeTrue()
        ->and($cerrado->gapBefore($siguiente, '2026-10-31'))->toBeFalse()
        ->and($cerrado->gapBefore($siguiente, '2027-04-01'))->toBeFalse();
})->group('RN-27');

it('no admite un periodo con el cese antes del alta, una baja efectiva sin cese ni una fecha imposible', function (callable $periodo): void {
    expect($periodo)->toThrow(InvalidArgumentException::class);
})->with([
    'cese antes del alta' => [static fn (): EmploymentPeriod => new EmploymentPeriod('2026-05-01', '2026-04-30')],
    'baja efectiva sin cese' => [static fn (): EmploymentPeriod => new EmploymentPeriod('2026-05-01', null, true)],
    'fecha imposible' => [static fn (): EmploymentPeriod => new EmploymentPeriod('2026-02-31')],
])->group('RN-27');

// --- La correccion de la fecha de cese --------------------------------------------

function corregirElCese(string $periodo, string $fecha, string $hoy = '2027-05-10', string $motivo = 'Trabajo hasta el 3 segun el cuadrante'): TerminationDateCorrection
{
    $historia = historiaDeLaReadmision();
    $elegido = $historia->find($periodo) ?? throw new RuntimeException('sin periodo');

    return TerminationDateCorrection::propose($historia, $elegido, $fecha, $hoy, $motivo);
}

it('corrige hacia una fecha posterior y hacia una anterior, y sabe si es el periodo en curso', function (string $fecha): void {
    $correccion = corregirElCese('p1', $fecha);

    expect($correccion->previousTerminatedOn)->toBe('2026-10-31')
        ->and($correccion->terminatedOn)->toBe($fecha)
        ->and($correccion->ofCurrentPeriod)->toBeFalse()
        ->and($correccion->reason)->toBe('Trabajo hasta el 3 segun el cuadrante');
})->with(['posterior' => ['2026-11-03'], 'anterior' => ['2026-10-15'], 'la vispera del periodo siguiente' => ['2027-03-31'], 'el dia del alta' => ['2026-05-01']])->group('RN-27');

it('rechaza la fecha que no cabe con el limite que la deja fuera', function (string $fecha, string $hoy, string $clave): void {
    try {
        corregirElCese('p1', $fecha, $hoy);
        $lanzada = null;
    } catch (InvalidTerminationCorrection $exception) {
        $lanzada = $exception->translationKey;
    }

    expect($lanzada)->toBe($clave);
})->with([
    'posterior a hoy' => ['2026-11-03', '2026-11-02', 'employees.errors.correction_after_today'],
    'anterior al alta' => ['2026-04-30', '2027-05-10', 'employees.errors.correction_before_hiring'],
    'el dia del alta siguiente' => ['2027-04-01', '2027-05-10', 'employees.errors.correction_reaches_next_period'],
    'la misma fecha' => ['2026-10-31', '2027-05-10', 'employees.errors.correction_unchanged'],
])->group('RN-27');

it('admite hoy como fecha de cese corregida', function (): void {
    expect(corregirElCese('p1', '2026-11-02', '2026-11-02')->terminatedOn)->toBe('2026-11-02');
})->group('RN-27');

it('no corrige un periodo sin baja efectiva ni con un motivo fuera de 10 a 1000 caracteres', function (): void {
    expect(fn (): TerminationDateCorrection => corregirElCese('p2', '2027-05-01'))->toThrow(OffboardingNotEffective::class)
        ->and(fn (): TerminationDateCorrection => corregirElCese('p1', '2026-11-03', motivo: '  corta    '))->toThrow(InvalidArgumentException::class)
        ->and(fn (): TerminationDateCorrection => corregirElCese('p1', '2026-11-03', motivo: str_repeat('a', 1001)))->toThrow(InvalidArgumentException::class)
        ->and(corregirElCese('p1', '2026-11-03', motivo: str_repeat('a', 10))->reason)->toHaveLength(10);
})->group('RN-27');
