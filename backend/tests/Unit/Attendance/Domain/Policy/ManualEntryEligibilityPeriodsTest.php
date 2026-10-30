<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use Tests\Support\Factory\ContinuationCeilingFactory;
use Tests\Support\Time\Instants;

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

/**
 * `null` si las marcas se admiten; la marca rechazada si no.
 */
function marcaRechazadaDelPeriodo(ManualEntryEligibility $persona, string $jornada, string $entrada, string $salida): ?FutureMark
{
    try {
        $persona->assertTimesAllowed(
            ShiftEntryTimes::closed(Instants::utc($entrada), Instants::utc($salida)),
            ContinuationCeilingFactory::standard(),
            WorkDate::fromIsoDate($jornada, new DateTimeZone('Europe/Madrid')),
        );
    } catch (ShiftEntryMarkAfterTermination $exception) {
        return $exception->mark;
    }

    return null;
}

it('acota las marcas del ultimo dia del periodo anterior con su cese, antes y despues de readmitir', function (string $entrada, string $salida, ?FutureMark $rechazo): void {
    // Cese el 31 de octubre de 2026: el dia acaba a la medianoche del 1 de
    // noviembre en Madrid (23:00 UTC del 31) y la cota de 12 h llega a las 11:00
    // UTC del 1. Readmitida, el periodo en curso no tiene cese: la cota es la
    // del periodo de la jornada, no la del periodo en curso.
    $antes = readmitidaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-05-01', '2026-10-31', [
        new EmploymentPeriod('2026-05-01', '2026-10-31', true),
    ]);

    expect(marcaRechazadaDelPeriodo($antes, '2026-10-31', $entrada, $salida))->toBe($rechazo)
        ->and(marcaRechazadaDelPeriodo(luciaReadmitida(), '2026-10-31', $entrada, $salida))->toBe($rechazo)
        ->and(marcaRechazadaDelPeriodo(luciaReadmitida(EmploymentStatus::ACTIVE, '2027-09-30'), '2026-10-31', $entrada, $salida))->toBe($rechazo);
})->with([
    'el turno de noche del ultimo dia' => ['2026-10-31 20:00', '2026-11-01 04:00', null],
    'la salida un segundo antes de la cota' => ['2026-10-31 20:00', '2026-11-01 10:59:59', null],
    'la salida en la cota' => ['2026-10-31 20:00', '2026-11-01 11:00', FutureMark::ClockOut],
    'la salida tres dias despues' => ['2026-10-31 20:00', '2026-11-03 06:00', FutureMark::ClockOut],
])->group('RN-27', 'RN-05', 'RF-PA-04');

it('en el periodo en curso sin cese no acota las marcas', function (): void {
    expect(marcaRechazadaDelPeriodo(luciaReadmitida(), '2027-06-01', '2027-06-01 06:00', '2027-06-01 14:00'))->toBeNull();
})->group('RN-27', 'RF-PA-04');
