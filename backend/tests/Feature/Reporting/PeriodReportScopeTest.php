<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El alcance del informe por periodo (**RF-ID-03**, regla dura 18, tarea 2.8).
 *
 * ## Lo que se defiende aqui, y por que no basta con la policy
 *
 * El escenario «Aislamiento por departamento» del doc 01 §11 dice que un
 * responsable de Cocina no accede a datos de Recepcion. En un informe eso tiene
 * una vuelta de tuerca: **tampoco a sus agregados**. De un total y una plantilla
 * se deduce una media, asi que servir «el hotel entero: 3.200 horas» a quien
 * alcanza un departamento es una fuga por agregacion aunque no aparezca ni un
 * nombre.
 *
 * Por eso el alcance entra **dentro** de la consulta y estas pruebas lo
 * comprueban ahi: se invoca el caso de uso con un `AccessScope` acotado en lugar
 * de pasar por el endpoint. La razon es que hoy ningun rol tiene a la vez
 * `reports:*` y alcance parcial —el `responsable_departamento` no lleva ese
 * ambito (§7.3)—, asi que por HTTP no hay forma de ejercitar el filtro. Que ese
 * rol no llega se prueba en `Tests\Feature\AuthorizationNegativeTest`; lo que
 * falta comprobar es que **el dia que llegue, la consulta ya esta acotada**, y
 * eso es lo que hay debajo.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, cocina: int, recepcion: int, deCocina: string, deRecepcion: string}
 */
function contextoDeAlcance(): array
{
    $site = WorkforceFixtures::site('Hotel con dos areas');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $deCocina = WorkforceFixtures::employee($site, $cocina);
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion);

    PeriodReportFixtures::workDay($site, $deCocina, '2026-03-02', '2026-03-02 08:00', '2026-03-02 16:00');
    PeriodReportFixtures::workDay($site, $deRecepcion, '2026-03-02', '2026-03-02 08:00', '2026-03-02 14:00');

    return [
        'site' => $site,
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'deCocina' => $deCocina,
        'deRecepcion' => $deRecepcion,
    ];
}

function informeConAlcance(AccessScope $alcance, ReportGrouping $agrupacion): PeriodReport
{
    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    return $caso->handle(
        new PeriodReportQuery(
            scope: $alcance,
            range: DateRange::between('2026-03-02', '2026-03-02'),
            granularity: ReportGranularity::Day,
            grouping: $agrupacion,
            departmentId: null,
            employeeUuid: null,
        ),
        maxRangeDays: 92,
        maxRows: 20000,
    );
}

it('no devuelve a un responsable de Cocina ni una fila de Recepcion', function (): void {
    $contexto = contextoDeAlcance();

    $informe = informeConAlcance(AccessScope::forDepartments($contexto['cocina']), ReportGrouping::Employee);

    expect($informe->employeeUuids())->toBe([$contexto['deCocina']]);
})->group('RF-ID-03');

it('tampoco le devuelve los agregados de Recepcion, ni sumados en el total del centro', function (): void {
    // La parte que un filtro aplicado DESPUES de agregar dejaria pasar: el total
    // del centro seria el del hotel entero, y de un total y una plantilla se
    // deduce una media.
    $contexto = contextoDeAlcance();

    $porCentro = informeConAlcance(AccessScope::forDepartments($contexto['cocina']), ReportGrouping::Site);

    expect($porCentro->workedMinutes())->toBe(480);

    $porDepartamento = informeConAlcance(AccessScope::forDepartments($contexto['cocina']), ReportGrouping::Department);

    expect($porDepartamento->rowCount())->toBe(1)
        ->and($porDepartamento->rows[0]->subject->departmentId)->toBe($contexto['cocina'])
        ->and($porDepartamento->workedMinutes())->toBe(480);

    // Y el control positivo: sin restriccion salen las dos areas y las 14 horas.
    $completo = informeConAlcance(AccessScope::unrestricted(), ReportGrouping::Site);

    expect($completo->workedMinutes())->toBe(480 + 360);
})->group('RF-ID-03', 'RF-IN-02');

it('a un responsable sin departamentos asignados no le devuelve a nadie', function (): void {
    // `forDepartments()` sin ninguno significa «nadie», no «sin restriccion». Es
    // el fallo que convertiria a un responsable recien creado en alguien que ve
    // el hotel entero, y por eso el predicado imposible esta en la consulta.
    contextoDeAlcance();

    $informe = informeConAlcance(AccessScope::forDepartments(), ReportGrouping::Employee);

    expect($informe->rowCount())->toBe(0)
        ->and($informe->workedMinutes())->toBe(0);
})->group('RF-ID-03');

it('el alcance lo resuelve el servidor del token y no se puede pedir', function (): void {
    // La otra mitad de RF-ID-03: no existe ningun parametro con el que ampliar
    // el alcance. `ScopeGuard` lo deduce del actor, y para quien no es una cuenta
    // de gestion falla cerrado.
    $contexto = contextoDeAlcance();

    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($contexto['cocina'])->update(['manager_user_id' => $responsable->id]);

    /** @var ScopeGuard $guard */
    $guard = app(ScopeGuard::class);

    $alcance = $guard->scopeOf($responsable);

    expect($alcance->isUnrestricted())->toBeFalse()
        ->and($alcance->departmentIds())->toBe([$contexto['cocina']]);

    // Y aun asi el endpoint le responde `403`: le falta `reports:*` (§7.3). Las
    // dos mitades dicen lo mismo, que es como tienen que ser.
    Api::as(ManagementUsers::tokenFor($responsable))
        ->get('/api/v1/reports/period', ['from' => '2026-03-02', 'to' => '2026-03-02'])
        ->assertStatus(403);
})->group('RF-ID-03', 'RQ-07');
