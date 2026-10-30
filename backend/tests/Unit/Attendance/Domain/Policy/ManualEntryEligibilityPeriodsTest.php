<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/*
 * **EL ALTA MANUAL DE UNA PERSONA READMITIDA** (RN-27, RF-PA-04, ADR-062 §11).
 *
 * Una jornada cae en alguno de sus periodos de alta. El hueco entre una baja y
 * la readmision siguiente no admite tramos; el periodo anterior, si. Despues del
 * ultimo cese, como con una sola baja.
 *
 * «Lucia» trabajo del 1 de mayo al 31 de octubre de 2026 y volvio el 1 de abril
 * de 2027.
 */

/**
 * @param  list<EmploymentPeriod>  $periods
 */
function readmitidaParaElAltaManual(EmploymentStatus $status, string $hiredOn, ?string $terminatedOn, array $periods): ManualEntryEligibility
{
    return ManualEntryEligibility::of(
        new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', $status, 1, null, $hiredOn, $terminatedOn, $periods),
    );
}

function luciaReadmitida(EmploymentStatus $status = EmploymentStatus::ACTIVE, ?string $cese = null): ManualEntryEligibility
{
    return readmitidaParaElAltaManual($status, '2027-04-01', $cese, [
        new EmploymentPeriod('2026-05-01', '2026-10-31', true),
        new EmploymentPeriod('2027-04-01', $cese, $status === EmploymentStatus::TERMINATED),
    ]);
}

function claveDelRechazoDelPeriodo(ManualEntryEligibility $persona, string $fecha): ?string
{
    try {
        $persona->assertWorkDateAllowed(WorkDate::fromIsoDate($fecha, new DateTimeZone('Europe/Madrid')));
    } catch (WorkDateOutsideEmployment $exception) {
        return $exception->translationKey;
    }

    return null;
}

it('admite las jornadas de cualquiera de sus periodos y rechaza las del hueco', function (string $fecha, ?string $clave): void {
    expect(claveDelRechazoDelPeriodo(luciaReadmitida(), $fecha))->toBe($clave);
})->with([
    'el periodo anterior' => ['2026-10-20', null],
    'el ultimo dia del periodo anterior' => ['2026-10-31', null],
    'el primer dia del hueco' => ['2026-11-01', 'attendance.errors.work_date_between_periods'],
    'en mitad del hueco' => ['2027-01-15', 'attendance.errors.work_date_between_periods'],
    'la vispera de la vuelta' => ['2027-03-31', 'attendance.errors.work_date_between_periods'],
    'el dia de la vuelta' => ['2027-04-01', null],
    'el periodo en curso' => ['2027-06-01', null],
    // En alta no hay limite hacia atras antes del primer alta, como antes.
    'antes del primer alta' => ['2026-01-10', null],
])->group('RN-27', 'RF-PA-04');

it('de baja otra vez, rechaza despues del ultimo cese y antes del primer alta', function (string $fecha, ?string $clave): void {
    expect(claveDelRechazoDelPeriodo(luciaReadmitida(EmploymentStatus::TERMINATED, '2027-09-30'), $fecha))->toBe($clave);
})->with([
    'el periodo anterior' => ['2026-06-01', null],
    'el hueco' => ['2026-12-01', 'attendance.errors.work_date_between_periods'],
    'despues del ultimo cese' => ['2027-10-01', 'attendance.errors.work_date_outside_employment'],
    'antes del primer alta' => ['2026-04-30', 'attendance.errors.work_date_outside_employment'],
])->group('RN-27', 'RN-14');

it('con una baja programada en el periodo en curso, rechaza despues de su cese', function (): void {
    expect(claveDelRechazoDelPeriodo(luciaReadmitida(EmploymentStatus::ACTIVE, '2027-09-30'), '2027-10-01'))
        ->toBe('attendance.errors.work_date_after_termination');
})->group('RN-27', 'RN-14');

it('con un solo periodo decide con las dos fechas de la ficha, como antes', function (): void {
    $una = readmitidaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-05-01', '2026-10-31', [
        new EmploymentPeriod('2026-05-01', '2026-10-31', true),
    ]);

    expect(claveDelRechazoDelPeriodo($una, '2026-11-01'))->toBe('attendance.errors.work_date_outside_employment')
        ->and(claveDelRechazoDelPeriodo($una, '2026-10-31'))->toBeNull();
})->group('RN-27', 'RN-14');
