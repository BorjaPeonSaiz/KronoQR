<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL INFORME POR PERIODO CUENTA «DE ALTA» POR PERIODOS** (RN-27, RF-IN-03,
 * RF-GP-04, ADR-062 §11 y Consecuencias).
 *
 * Una persona con un periodo, un hueco y otro periodo: los dias del hueco no son
 * dias de alta sin actividad, ni absentismo, ni dias sin contrato. Los de los
 * dos periodos, si. Antes de la 2.3.0 el informe leia el alta y el cese de la
 * ficha, que tras una readmision solo describen el periodo en curso.
 *
 * Trabajo hasta el 10 de marzo y volvio el 13. El informe va del 9 al 15.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    LicenseKeys::grantAll();
});

/** La persona de baja el 10 y readmitida el 13, por las transiciones que admite la base. */
function readmitidaParaElInforme(int $site): string
{
    $uuid = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'));
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');

    DB::update("UPDATE employees SET status = 'terminated', terminated_at = '2026-03-10' WHERE id = ?", [$id]);
    DB::update("UPDATE employment_periods SET terminated_on = '2026-03-10', offboarded_at = now() WHERE employee_id = ?", [$id]);
    DB::update("UPDATE employees SET status = 'active', hired_at = '2026-03-13', terminated_at = NULL WHERE id = ?", [$id]);
    DB::insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2026-03-13')", [$id]);

    return $uuid;
}

it('no cuenta el hueco entre los dos periodos como dias de alta sin actividad ni sin contrato', function (): void {
    $site = WorkforceFixtures::site('Hotel de la readmision');
    readmitidaParaElInforme($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $informe = Api::as($token)
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'day'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    /** @var list<array<string, mixed>> $filas */
    $filas = $informe->json('data');
    $porDia = [];

    foreach ($filas as $fila) {
        /** @var array{from: string} $periodo */
        $periodo = $fila['period'];
        $porDia[$periodo['from']] = [$fila['unjustified_absence_days'], $fila['days_without_contract']];
    }

    // Los dos dias del primer periodo y los tres del segundo, de alta; el hueco, no.
    expect($porDia['2026-03-09'])->toBe([1, 1])
        ->and($porDia['2026-03-10'])->toBe([1, 1])
        ->and($porDia['2026-03-11'])->toBe([0, 0])
        ->and($porDia['2026-03-12'])->toBe([0, 0])
        ->and($porDia['2026-03-13'])->toBe([1, 1])
        ->and($porDia['2026-03-15'])->toBe([1, 1]);
})->group('RN-27', 'RF-IN-03', 'RF-GP-04');
