<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Escenario «Semana por encima de la jornada ordinaria» del doc 01 §11 (RN-17),
 * con el actor que dice el escenario: **el responsable** del departamento, no
 * RRHH, y con la deteccion de incidencias ejecutada de verdad (G8 de la
 * verificacion 2.1.0).
 *
 * Cinco jornadas de 8 h 12 min del lunes 9 al viernes 13 de marzo de 2026 son
 * 41 h en la semana del 9 al 15 con el perfil `ES-hosteleria` (40 h, semana
 * que empieza el lunes). Ninguna jornada pasa de las 9 h diarias y entre una
 * y otra hay 15 h 48 min de descanso: si la deteccion abriera algo, solo
 * podria ser por la semana.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
    Notification::fake();

    // Lunes 16 de marzo: la semana del 9 al 15 ya ha terminado y cae dentro de
    // la ventana de la deteccion.
    FrozenTime::at('2026-03-16 08:00:00');
});

/**
 * @return array{employee: string, token: string}
 */
function semanaExcedidaConSuResponsable(): array
{
    $site = WorkforceFixtures::site('Hotel de la semana', 'Europe/Madrid');
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $pisos = WorkforceFixtures::department($site, 'Pisos');
    Department::query()->whereKey($pisos)->update(['manager_user_id' => $responsable->id]);
    $employee = WorkforceFixtures::employee($site, $pisos, 'active', 'Ana', 'Lopez');

    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:12');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 09:00', '2026-03-10 17:12');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-11', '2026-03-11 09:00', '2026-03-11 17:12');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-12', '2026-03-12 09:00', '2026-03-12 17:12');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-13', '2026-03-13 09:00', '2026-03-13 17:12');

    return ['employee' => $employee, 'token' => ManagementUsers::tokenFor($responsable)];
}

it('enseña al responsable la semana del 9 al 15 con 41 horas frente a 40 al consultar el jueves', function (): void {
    $escenario = semanaExcedidaConSuResponsable();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-12', 'to' => '2026-03-12'])
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.employee.uuid'))->toBe($escenario['employee'])
        ->and($respuesta->json('data.0.rule'))->toBe('weekly_excess')
        ->and($respuesta->json('data.0.week.starts_on'))->toBe('2026-03-09')
        ->and($respuesta->json('data.0.week.ends_on'))->toBe('2026-03-15')
        ->and($respuesta->json('data.0.measured_minutes'))->toBe(2460)
        ->and($respuesta->json('data.0.threshold_minutes'))->toBe(2400)
        ->and($respuesta->json('data.0.incident'))->toBeNull();
})->group('RN-17', 'RF-PA-06', 'RS-05');

it('no abre ninguna incidencia por la semana excedida al ejecutar la deteccion', function (): void {
    semanaExcedidaConSuResponsable();

    $salida = Artisan::call('attendance:detect-incidents', ['--days' => 14]);

    expect($salida)->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0);
})->group('RN-17', 'RF-PR-01');

it('la misma deteccion si ve esa semana: una jornada de 9 h 30 dentro de ella abre long_shift', function (): void {
    // Control de la prueba de arriba: sin el, «cero incidencias» pasaria igual
    // con una ventana de deteccion que no llegara a la semana del 9 al 15.
    $site = WorkforceFixtures::site('Hotel de la semana', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'));
    PeriodReportFixtures::workDay($site, $employee, '2026-03-11', '2026-03-11 09:00', '2026-03-11 18:30');

    Artisan::call('attendance:detect-incidents', ['--days' => 14]);

    expect(DB::table('incidents')->pluck('type')->all())->toBe(['long_shift']);
})->group('RN-11', 'RF-PR-01');
