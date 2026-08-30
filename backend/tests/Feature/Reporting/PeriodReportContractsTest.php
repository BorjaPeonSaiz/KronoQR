<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use DateTimeImmutable;
use DateTimeZone;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La comparativa de horas trabajadas frente a contratadas (RF-IN-03, RF-GP-02,
 * tarea 2.8).
 *
 * ## La formula, escrita una vez y comprobada en los dos sitios
 *
 * Lo contratado de un periodo es **Σ dias naturales de vigencia × horas
 * semanales ÷ 7**, en minutos y redondeado una sola vez al final. Vive en
 * `EmploymentContract::contractedMinutesForDays()` y la consulta del informe la
 * reproduce en SQL —el dominio no puede agregar quinientas personas fila a
 * fila—. Esa duplicacion esta asumida y **la ata la primera prueba de este
 * fichero**: sin ella seria una bomba de relojeria.
 *
 * ## El cambio de contrato a mitad de periodo
 *
 * Es el caso que justifica que `employment_contracts` sea una tabla historizada
 * y no cuatro columnas en la ficha. Si el informe comparara contra el ultimo
 * contrato, marzo pasaria a medirse con las horas de julio en cuanto alguien
 * firmara un anexo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeContratos(): array
{
    $site = WorkforceFixtures::site('Hotel de contratos');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
    ];
}

it('prorratea lo contratado por dia natural, y el SQL da lo mismo que el dominio', function (): void {
    // Un contrato de 40 h vigente los 31 dias de marzo:
    //   31 x 40 x 60 / 7 = 10.628,57... -> 10.629 minutos.
    // El numero esta escrito a mano y ademas se contrasta con el modelo de
    // dominio: si algun dia las dos expresiones de la formula divergieran, esta
    // prueba lo dice antes que una nomina.
    $contexto = contextoDeContratos();

    PeriodReportFixtures::contract($contexto['employee'], 40.0, '2026-01-01');

    $esperadoPorElDominio = (new EmploymentContract(
        employeeUuid: $contexto['employee'],
        weeklyHours: 40.0,
        annualHours: null,
        scheduleType: ScheduleType::Shifts,
        validFrom: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        validTo: null,
    ))->contractedMinutesForDays(31);

    expect($esperadoPorElDominio)->toBe(10629);

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.contracted_minutes', 10629)
        ->assertJsonPath('data.0.contracted', '177:09')
        // Sin fichajes, la desviacion es todo lo contratado, en negativo. «Exceso
        // de jornada» es una cantidad y no puede ser negativa: se queda en cero.
        ->assertJsonPath('data.0.worked_minutes', 0)
        ->assertJsonPath('data.0.deviation_minutes', -10629)
        ->assertJsonPath('data.0.deviation', '-177:09')
        ->assertJsonPath('data.0.overtime_minutes', 0)
        ->assertJsonPath('data.0.overtime', '00:00');
})->group('RF-IN-03', 'RF-GP-02');

it('compara cada dia contra el contrato vigente ese dia cuando cambia a mitad de periodo', function (): void {
    // Marzo de 2026 con un cambio el dia 16: 15 dias a 20 h y 16 dias a 40 h.
    //   (15 x 20 + 16 x 40) x 60 / 7 = (300 + 640) x 60 / 7 = 8057,14... -> 8057
    // Con el contrato ultimo aplicado a todo el mes saldrian 10.629, y con el
    // primero 5.314: los tres numeros son bien distintos, que es lo que hace que
    // esta prueba signifique algo.
    $contexto = contextoDeContratos();

    PeriodReportFixtures::contract($contexto['employee'], 20.0, '2026-01-01', '2026-03-15');
    PeriodReportFixtures::contract($contexto['employee'], 40.0, '2026-03-16');

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-20', '2026-03-20 08:00', '2026-03-20 16:00');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.contracted_minutes', 8057)
        ->assertJsonPath('data.0.worked_minutes', 480)
        ->assertJsonPath('data.0.deviation_minutes', 480 - 8057)
        ->assertJsonPath('data.0.days_without_contract', 0)
        ->assertJsonPath('meta.contract_coverage.complete', true);
})->group('RF-IN-03', 'RF-GP-02');

it('informa de los dias sin contrato en vez de suponer uno', function (): void {
    // Sin esta cifra, un periodo en el que a media plantilla se le olvido
    // registrar el contrato saldria con una desviacion enorme y con aspecto de
    // dato bueno. Las dos salidas posibles eran suponer un contrato —inventar un
    // dato de nomina— o decirlo. Se dice.
    $contexto = contextoDeContratos();

    // El contrato empieza el 16; del 1 al 15 la persona estaba de alta desde el
    // 1 de enero (el alta que fija la fixture) y sin contrato registrado.
    PeriodReportFixtures::contract($contexto['employee'], 40.0, '2026-03-16');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.days_without_contract', 15)
        ->assertJsonPath('meta.contract_coverage.days_without_contract', 15)
        ->assertJsonPath('meta.contract_coverage.employees_without_contract', 1)
        ->assertJsonPath('meta.contract_coverage.complete', false)
        // Y lo contratado solo cuenta los dieciseis dias que si tenian contrato:
        // 16 x 40 x 60 / 7 = 5485,71... -> 5486.
        ->assertJsonPath('data.0.contracted_minutes', 5486);
})->group('RF-IN-03', 'RF-GP-02');

it('cuenta el exceso de jornada solo cuando se ha trabajado por encima', function (): void {
    // 20 h semanales durante los siete dias del periodo:
    //   7 x 20 x 60 / 7 = 1200 minutos exactos.
    // Se trabajan 1440 (tres jornadas de 8 h): 240 minutos de exceso.
    $contexto = contextoDeContratos();

    PeriodReportFixtures::contract($contexto['employee'], 20.0, '2026-01-01');

    foreach (['2026-03-02', '2026-03-03', '2026-03-04'] as $dia) {
        PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], $dia, $dia.' 08:00', $dia.' 16:00');
    }

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-02', 'to' => '2026-03-08', 'granularity' => 'range'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.contracted_minutes', 1200)
        ->assertJsonPath('data.0.contracted', '20:00')
        ->assertJsonPath('data.0.worked_minutes', 1440)
        ->assertJsonPath('data.0.deviation_minutes', 240)
        ->assertJsonPath('data.0.overtime_minutes', 240)
        ->assertJsonPath('data.0.overtime', '04:00');
})->group('RF-IN-03');
