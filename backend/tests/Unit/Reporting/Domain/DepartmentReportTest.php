<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\ReportSubject;

/*
 * El informe agregado por departamento, en el dominio (**RF-IN-02**; hueco de
 * nivel de `TestLevelGateTest` cerrado en la tarea 22.2 de la 2.3.0).
 *
 * La SUMA por departamento la hace PostgreSQL (`GROUP BY` de
 * `DatabasePeriodReportReader`) y la prueban `PeriodReportTest` (feature) y
 * `PeriodReportVolumeTest` (integracion). Lo que es del dominio, y se prueba aqui
 * sin base de datos, es lo que el informe HACE con esas filas:
 *
 * - el cubo de quien no tiene departamento es una fila de departamento, y entra
 *   en los totales: sin el, la suma de los departamentos no cuadraria con el
 *   total del centro y nadie sabria por que faltan horas;
 * - una fila de departamento no habla de ninguna persona, asi que el informe
 *   agregado no da ningun `employee_uuid` al asiento de `audit_log` (RS-05).
 */

function departamentoDelInforme(?int $id, ?string $name, int $worked, int $contracted): PeriodReportRow
{
    return new PeriodReportRow(
        subject: ReportSubject::department($id, $name),
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-01-31'),
        workedMinutes: $worked,
        shiftCount: 40,
        daysInPeriod: 31,
        daysWithActivity: 22,
        openShiftDays: 0,
        incidentDays: 0,
        contractedMinutes: $contracted,
        daysWithoutContract: 0,
        absenceDays: 0,
        holidayDays: 0,
        unjustifiedAbsenceDays: 0,
    );
}

/**
 * @param  list<PeriodReportRow>  $rows
 */
function informePorDepartamento(array $rows): PeriodReport
{
    return new PeriodReport(
        rows: $rows,
        range: DateRange::between('2026-01-01', '2026-01-31'),
        granularity: ReportGranularity::Month,
        grouping: ReportGrouping::Department,
        timeZone: 'Europe/Madrid',
        generatedAt: new DateTimeImmutable('2026-02-01T07:00:00+00:00'),
        criteria: [],
        contractCoverage: new ContractCoverage(0, 0),
    );
}

it('suma en el total trabajado el cubo de quien no tiene departamento', function (): void {
    $informe = informePorDepartamento([
        departamentoDelInforme(3, 'Cocina', 9_600, 9_600),
        departamentoDelInforme(5, 'Recepcion', 4_800, 4_800),
        departamentoDelInforme(null, null, 480, 480),
    ]);

    expect($informe->workedMinutes())->toBe(14_880);
})->group('RF-IN-02');

it('suma en el total contratado el cubo de quien no tiene departamento', function (): void {
    $informe = informePorDepartamento([
        departamentoDelInforme(3, 'Cocina', 9_000, 9_600),
        departamentoDelInforme(null, null, 0, 2_400),
    ]);

    expect($informe->contractedMinutes())->toBe(12_000);
})->group('RF-IN-02', 'RF-IN-08');

it('no da ninguna persona al asiento de un informe agregado por departamento', function (): void {
    $informe = informePorDepartamento([
        departamentoDelInforme(3, 'Cocina', 9_600, 9_600),
        departamentoDelInforme(null, null, 480, 480),
    ]);

    expect($informe->employeeUuids())->toBe([]);
})->group('RF-IN-02', 'RS-05');

it('hace del cubo sin departamento una fila de departamento sin persona, sin clave y sin nombre', function (): void {
    $cubo = ReportSubject::department(null, null);

    expect($cubo->kind)->toBe(ReportGrouping::Department)
        ->and($cubo->departmentId)->toBeNull()
        ->and($cubo->label)->toBeNull()
        ->and($cubo->employeeUuid)->toBeNull()
        ->and($cubo->fullName)->toBeNull();
})->group('RF-IN-02');

it('atribuye la fila de un departamento a su clave y su nombre y a ninguna persona', function (): void {
    $cocina = ReportSubject::department(3, 'Cocina');

    expect($cocina->kind)->toBe(ReportGrouping::Department)
        ->and($cocina->departmentId)->toBe(3)
        ->and($cocina->label)->toBe('Cocina')
        ->and($cocina->employeeUuid)->toBeNull()
        ->and($cocina->employeeCode)->toBeNull()
        ->and($cocina->firstName)->toBeNull()
        ->and($cocina->lastName)->toBeNull();
})->group('RF-IN-02');

it('ofrece los tres agregados que pide el informe: persona, departamento y centro', function (): void {
    expect(ReportGrouping::names())->toBe(['employee', 'department', 'site']);
})->group('RF-IN-02');
