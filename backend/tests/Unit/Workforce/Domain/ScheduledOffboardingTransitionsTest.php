<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\ImmediateOffboardingNotDue;
use App\Modules\Workforce\Domain\Exception\OffboardingNotScheduled;
use App\Modules\Workforce\Domain\Exception\ScheduledOffboardingDue;
use App\Modules\Workforce\Domain\Exception\ScheduledOffboardingNotDue;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * **LA BAJA PROGRAMADA Y SUS TRANSICIONES** (RN-14, RF-GP-03, ADR-062 §3 y §4).
 *
 * La tabla de `Employee::offboard()` fila a fila, con y sin «Aplicar ya», para el
 * pasado, hoy y el futuro; la reprogramacion; la anulacion antes, el mismo dia y
 * despues del cese; y la aplicacion de la tarea, solo si la baja vencio. «Hoy» es
 * el 2026-10-02 en todas: el dominio lo recibe ya resuelto y compara fechas.
 */

function fichaDeLaBajaProgramada(
    EmploymentStatus $status = EmploymentStatus::ACTIVE,
    ?string $terminatedAt = null,
    string $hiredAt = '2026-01-15',
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
        hiredAt: new DateTimeImmutable($hiredAt),
        terminatedAt: $terminatedAt === null ? null : new DateTimeImmutable($terminatedAt),
        locale: 'es',
    );
}

function diaDeLaBajaProgramada(string $date = '2026-10-02'): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

it('decide baja en el acto o programada con la fecha de cese y aplicar ya', function (string $cese, bool $aplicarYa, ?EmploymentStatus $estado): void {
    $baja = fn (): Employee => fichaDeLaBajaProgramada()->offboard(diaDeLaBajaProgramada($cese), diaDeLaBajaProgramada(), $aplicarYa);

    if (! $estado instanceof EmploymentStatus) {
        expect($baja)->toThrow(ImmediateOffboardingNotDue::class);

        return;
    }

    $resultado = $baja();

    expect($resultado->status)->toBe($estado)
        ->and($resultado->terminatedAt?->format('Y-m-d'))->toBe($cese)
        ->and($resultado->isOffboardingScheduled())->toBe($estado !== EmploymentStatus::TERMINATED);
})->with([
    'pasado sin aplicar ya: en el acto' => ['2026-10-01', false, EmploymentStatus::TERMINATED],
    'pasado con aplicar ya: en el acto' => ['2026-10-01', true, EmploymentStatus::TERMINATED],
    'hoy sin aplicar ya: programada' => ['2026-10-02', false, EmploymentStatus::ACTIVE],
    'hoy con aplicar ya: en el acto' => ['2026-10-02', true, EmploymentStatus::TERMINATED],
    'futuro sin aplicar ya: programada' => ['2026-10-31', false, EmploymentStatus::ACTIVE],
    'futuro con aplicar ya: rechazada' => ['2026-10-31', true, null],
])->group('RN-14', 'RF-GP-03');

it('rechaza aplicar ya con un cese futuro con su clave, sus fechas y su mensaje tecnico', function (): void {
    try {
        fichaDeLaBajaProgramada()->offboard(diaDeLaBajaProgramada('2026-10-31'), diaDeLaBajaProgramada(), true);
    } catch (ImmediateOffboardingNotDue $rechazo) {
        expect($rechazo->translationKey)->toBe('employees.errors.apply_now_requires_due_termination')
            ->and($rechazo->parameters)->toBe(['terminated_on' => '2026-10-31', 'today' => '2026-10-02'])
            ->and($rechazo->getMessage())->toBe(
                'Apply now needs a termination date of today or earlier, not 2026-10-31 (today is 2026-10-02 at the site, RN-14).',
            );

        return;
    }

    throw new RuntimeException('Se esperaba ImmediateOffboardingNotDue.');
})->group('RN-14', 'RF-GP-03');

it('programa la baja de una persona suspendida sin cambiarle el estado', function (): void {
    $baja = fichaDeLaBajaProgramada(status: EmploymentStatus::SUSPENDED)
        ->offboard(diaDeLaBajaProgramada('2026-10-31'), diaDeLaBajaProgramada());

    expect($baja->status)->toBe(EmploymentStatus::SUSPENDED)
        ->and($baja->isOffboardingScheduled())->toBeTrue()
        ->and($baja->canClock())->toBeFalse();
})->group('RN-14', 'RN-25');

it('con la baja programada la persona sigue fichando hasta su ultimo dia', function (): void {
    $baja = fichaDeLaBajaProgramada()->offboard(diaDeLaBajaProgramada('2026-10-31'), diaDeLaBajaProgramada());

    expect($baja->canClock())->toBeTrue();
})->group('RN-14');

it('reprograma una baja programada a otra fecha de hoy o posterior', function (string $nueva, bool $aplicarYa, EmploymentStatus $estado): void {
    $programada = fichaDeLaBajaProgramada(terminatedAt: '2026-10-31');

    $reprogramada = $programada->offboard(diaDeLaBajaProgramada($nueva), diaDeLaBajaProgramada(), $aplicarYa);

    expect($reprogramada->status)->toBe($estado)
        ->and($reprogramada->terminatedAt?->format('Y-m-d'))->toBe($nueva);
})->with([
    'a otro dia futuro' => ['2026-11-15', false, EmploymentStatus::ACTIVE],
    'a hoy: sigue programada' => ['2026-10-02', false, EmploymentStatus::ACTIVE],
    'a hoy con aplicar ya: en el acto' => ['2026-10-02', true, EmploymentStatus::TERMINATED],
    'a un dia pasado: en el acto' => ['2026-09-30', false, EmploymentStatus::TERMINATED],
])->group('RN-14', 'RF-GP-03');

it('no cambia la fecha de una baja programada cuyo cese ya paso, pero la misma fecha la aplica', function (): void {
    // Cese el 30 y hoy el 2: la baja ya rige. Otra fecha no; la misma, en el acto.
    $vencida = fichaDeLaBajaProgramada(terminatedAt: '2026-09-30');

    expect(fn (): Employee => $vencida->offboard(diaDeLaBajaProgramada('2026-10-15'), diaDeLaBajaProgramada()))
        ->toThrow(ScheduledOffboardingDue::class)
        ->and(fn (): Employee => $vencida->offboard(diaDeLaBajaProgramada('2026-09-28'), diaDeLaBajaProgramada()))
        ->toThrow(ScheduledOffboardingDue::class)
        ->and($vencida->offboard(diaDeLaBajaProgramada('2026-09-30'), diaDeLaBajaProgramada())->status)
        ->toBe(EmploymentStatus::TERMINATED);
})->group('RN-14', 'RF-GP-03');

it('no programa ni aplica la baja de quien ya la tiene efectiva', function (bool $aplicarYa): void {
    $efectiva = fichaDeLaBajaProgramada(status: EmploymentStatus::TERMINATED, terminatedAt: '2026-09-30');

    expect(fn (): Employee => $efectiva->offboard(diaDeLaBajaProgramada('2026-10-31'), diaDeLaBajaProgramada(), $aplicarYa))
        ->toThrow(EmployeeAlreadyTerminated::class);
})->with(['sin aplicar ya' => [false], 'con aplicar ya' => [true]])->group('RN-14', 'RF-GP-03');

it('anula una baja programada mientras el cese no ha pasado', function (string $hoy): void {
    $anulada = fichaDeLaBajaProgramada(terminatedAt: '2026-10-02')->cancelScheduledOffboarding(diaDeLaBajaProgramada($hoy));

    expect($anulada->terminatedAt)->toBeNull()
        ->and($anulada->status)->toBe(EmploymentStatus::ACTIVE)
        ->and($anulada->isOffboardingScheduled())->toBeFalse();
})->with([
    'hoy anterior al cese' => ['2026-10-01'],
    'hoy es el dia del cese' => ['2026-10-02'],
])->group('RN-14', 'RF-GP-03');

it('no anula una baja programada cuyo cese ya paso, y dice las dos fechas', function (): void {
    try {
        fichaDeLaBajaProgramada(terminatedAt: '2026-10-02')->cancelScheduledOffboarding(diaDeLaBajaProgramada('2026-10-03'));
    } catch (ScheduledOffboardingDue $rechazo) {
        expect($rechazo->translationKey)->toBe('employees.errors.offboarding_due')
            ->and($rechazo->parameters)->toBe(['terminated_on' => '2026-10-02', 'today' => '2026-10-03']);

        return;
    }

    throw new RuntimeException('Se esperaba ScheduledOffboardingDue.');
})->group('RN-14', 'RF-GP-03');

it('no anula lo que no es una baja programada', function (Employee $ficha, string $excepcion): void {
    expect(fn (): Employee => $ficha->cancelScheduledOffboarding(diaDeLaBajaProgramada()))->toThrow($excepcion);
})->with([
    'sin fecha de cese' => [fn (): Employee => fichaDeLaBajaProgramada(), OffboardingNotScheduled::class],
    'con la baja efectiva' => [
        fn (): Employee => fichaDeLaBajaProgramada(status: EmploymentStatus::TERMINATED, terminatedAt: '2026-10-31'),
        EmployeeAlreadyTerminated::class,
    ],
])->group('RN-14', 'RF-GP-03');

it('la tarea la aplica solo cuando el cese es anterior a hoy, y con la fecha del cese', function (): void {
    $programada = fichaDeLaBajaProgramada(terminatedAt: '2026-10-02');

    expect($programada->isOffboardingDue(diaDeLaBajaProgramada('2026-10-02')))->toBeFalse()
        ->and($programada->isOffboardingDue(diaDeLaBajaProgramada('2026-10-03')))->toBeTrue()
        ->and(fn (): Employee => $programada->applyScheduledOffboarding(diaDeLaBajaProgramada('2026-10-02')))
        ->toThrow(ScheduledOffboardingNotDue::class);

    // Tres dias tarde: la fecha sigue siendo la del cese.
    $aplicada = $programada->applyScheduledOffboarding(diaDeLaBajaProgramada('2026-10-06'));

    expect($aplicada->status)->toBe(EmploymentStatus::TERMINATED)
        ->and($aplicada->terminatedAt?->format('Y-m-d'))->toBe('2026-10-02')
        ->and($aplicada->isOffboardingScheduled())->toBeFalse()
        ->and($aplicada->isOffboardingDue(diaDeLaBajaProgramada('2026-10-06')))->toBeFalse();
})->group('RN-14', 'RF-GP-03');

it('la tarea no aplica nada a quien no tiene baja programada', function (Employee $ficha): void {
    expect(fn (): Employee => $ficha->applyScheduledOffboarding(diaDeLaBajaProgramada('2026-12-31')))
        ->toThrow(ScheduledOffboardingNotDue::class)
        ->and($ficha->isOffboardingDue(diaDeLaBajaProgramada('2026-12-31')))->toBeFalse();
})->with([
    'sin fecha de cese' => [fn (): Employee => fichaDeLaBajaProgramada()],
    'con la baja efectiva' => [fn (): Employee => fichaDeLaBajaProgramada(status: EmploymentStatus::TERMINATED, terminatedAt: '2026-10-01')],
])->group('RN-14');

it('la baja programada sobrevive a la suspension y a la reincorporacion', function (): void {
    $programada = fichaDeLaBajaProgramada(terminatedAt: '2026-10-31');

    expect($programada->suspend()->terminatedAt?->format('Y-m-d'))->toBe('2026-10-31')
        ->and($programada->suspend()->reinstate()->isOffboardingScheduled())->toBeTrue()
        ->and($programada->updateProfile(firstName: 'Yusuf')->terminatedAt?->format('Y-m-d'))->toBe('2026-10-31');
})->group('RN-14', 'RN-25');

it('con un alta futura y el cese en el alta, aplicar ya es la unica excepcion al 422 del cese futuro', function (): void {
    // Enmienda de ADR-062 §3: sin «Aplicar ya» se programa (el extra de un solo
    // dia); con el, es la baja sin efectos de la contratacion que no empezo.
    $ficha = fichaDeLaBajaProgramada(hiredAt: '2026-10-15');

    expect($ficha->offboard(diaDeLaBajaProgramada('2026-10-15'), diaDeLaBajaProgramada())->isOffboardingScheduled())->toBeTrue()
        ->and($ficha->offboard(diaDeLaBajaProgramada('2026-10-15'), diaDeLaBajaProgramada(), true)->status)->toBe(EmploymentStatus::TERMINATED)
        ->and(fn (): Employee => $ficha->offboard(diaDeLaBajaProgramada('2026-10-20'), diaDeLaBajaProgramada(), true))
        ->toThrow(ImmediateOffboardingNotDue::class);
})->group('RN-14', 'RF-GP-03');

it('al cambiar la fecha de una baja vencida dice que ya no se puede cambiar, no que no se puede anular', function (): void {
    try {
        fichaDeLaBajaProgramada(terminatedAt: '2026-09-30')->offboard(diaDeLaBajaProgramada('2026-10-15'), diaDeLaBajaProgramada());
    } catch (ScheduledOffboardingDue $rechazo) {
        expect($rechazo->translationKey)->toBe('employees.errors.offboarding_due_reschedule')
            ->and($rechazo->parameters)->toBe(['terminated_on' => '2026-09-30', 'today' => '2026-10-02']);

        return;
    }

    throw new RuntimeException('Se esperaba ScheduledOffboardingDue.');
})->group('RN-14', 'RF-GP-03');
