<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/reports/period` con ausencias y festivos: **el informe deja de
 * contarlos como absentismo injustificado** (**RF-GP-04**, tarea 3.10,
 * decision 7).
 *
 * Es el compromiso del doc 05 §5.5, literal: «registro de ausencias (vacaciones,
 * baja, permiso) para que los informes no las cuenten como absentismo
 * injustificado».
 *
 * ## Fichero aparte del informe, a proposito
 *
 * `PeriodReportTest` defiende el calculo de horas (2.8) y este defiende el
 * efecto de 3.10 sobre el. Son dos tareas y dos motivos para cambiar; juntos,
 * quien rompa uno tendria que leer ochocientas lineas para saber cual.
 *
 * Cada respuesta pasa por Spectator: las tres columnas nuevas son **requeridas**
 * en el contrato, asi que una respuesta que se las dejara fuera rompe aqui antes
 * que en los tres frontends.
 *
 * ## Las ausencias se siembran con `INSERT`, no por su caso de uso
 *
 * Lo que se ejercita aqui es el **informe**. Si fueran por `POST /absences`, un
 * cambio en las validaciones de aquel pondria en rojo estas pruebas sin que el
 * informe hubiera cambiado. Ver el docblock de `PeriodReportFixtures::absence()`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El informe por periodo es funcionalidad ACCESORIA (ADR-023): sin licencia
    // responde `402`. Su degradacion tiene fichero propio.
    LicenseKeys::grantAll();
});

/**
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeInformeConAusencias(): array
{
    $site = WorkforceFixtures::site('Hotel de ausencias');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos')),
    ];
}

/**
 * Los festivos del perfil vigente, que es de donde los toma el informe (regla
 * dura 14: el dominio no consulta configuracion).
 *
 * @param  list<string>  $dias
 */
function festivosDelPerfil(array $dias): void
{
    DB::table('compliance_profiles')
        ->where('is_default', true)
        ->update(['holiday_calendar' => json_encode($dias, JSON_THROW_ON_ERROR)]);
}

it('no suma absentismo los tres dias de una baja, y deja igual el anterior y el posterior', function (): void {
    /*
     * EL ESCENARIO DE LA FICHA, LITERAL: «un empleado con una baja de tres dias
     * no aparece como ausente injustificado ningun dia de esos tres, ni el
     * anterior ni el siguiente cambian de estado, y el informe del periodo cuadra
     * antes y despues con la unica diferencia de esos tres dias».
     *
     * Se saca el mismo informe dos veces —antes y despues de registrar la baja—
     * y se comparan. Comparar es lo que hace la prueba util: con una sola
     * llamada habria que escribir a mano lo que se espera, y un error de
     * enunciado pasaria por bueno.
     */
    $contexto = contextoDeInformeConAusencias();

    $sinBaja = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    // Siete dias sin fichar y sin nada registrado: los siete sin justificar.
    $sinBaja
        ->assertJsonPath('data.0.absence_days', 0)
        ->assertJsonPath('data.0.holiday_days', 0)
        ->assertJsonPath('data.0.unjustified_absence_days', 7);

    PeriodReportFixtures::absence($contexto['employee'], 'sick_leave', '2026-03-11', '2026-03-13');

    $conBaja = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'day'])
        ->assertValidResponse(200);

    /** @var list<array<string, mixed>> $filas */
    $filas = $conBaja->json('data');

    $porDia = [];

    foreach ($filas as $fila) {
        /** @var array{from: string} $periodo */
        $periodo = $fila['period'];

        $porDia[$periodo['from']] = [
            'ausencia' => $fila['absence_days'],
            'sin justificar' => $fila['unjustified_absence_days'],
        ];
    }

    // Los tres dias de la baja: ausencia, no absentismo. Los extremos incluidos.
    expect($porDia['2026-03-11'])->toBe(['ausencia' => 1, 'sin justificar' => 0])
        ->and($porDia['2026-03-12'])->toBe(['ausencia' => 1, 'sin justificar' => 0])
        ->and($porDia['2026-03-13'])->toBe(['ausencia' => 1, 'sin justificar' => 0]);

    // El anterior y el posterior, exactamente como estaban.
    expect($porDia['2026-03-10'])->toBe(['ausencia' => 0, 'sin justificar' => 1])
        ->and($porDia['2026-03-14'])->toBe(['ausencia' => 0, 'sin justificar' => 1]);

    // Y el resto del informe no se mueve: la unica diferencia son esos tres dias.
    $conBaja = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidResponse(200);

    $conBaja
        ->assertJsonPath('data.0.absence_days', 3)
        ->assertJsonPath('data.0.unjustified_absence_days', 4)
        // Lo que NO cambia: las horas, los dias del periodo y los dias sin
        // actividad. Registrar una baja no inventa ni borra una jornada.
        ->assertJsonPath('data.0.worked_minutes', $sinBaja->json('data.0.worked_minutes'))
        ->assertJsonPath('data.0.days_in_period', $sinBaja->json('data.0.days_in_period'))
        ->assertJsonPath('data.0.days_without_activity', $sinBaja->json('data.0.days_without_activity'));
})->group('RF-GP-04', 'RF-IN-01');

it('agrega los tres contadores en una fila y respeta la invariante del absentismo', function (): void {
    // Una semana con los cuatro tipos de dia: 1 trabajado, 1 festivo, 3 de baja
    // y 2 sin justificar. Y `unjustified_absence_days <= days_without_activity`,
    // que es la invariante que declara `PeriodReportRow`.
    $contexto = contextoDeInformeConAusencias();

    festivosDelPerfil(['2026-03-10']);

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::absence($contexto['employee'], 'sick_leave', '2026-03-11', '2026-03-13');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $respuesta
        ->assertJsonPath('data.0.days_in_period', 7)
        ->assertJsonPath('data.0.days_with_activity', 1)
        ->assertJsonPath('data.0.days_without_activity', 6)
        ->assertJsonPath('data.0.absence_days', 3)
        ->assertJsonPath('data.0.holiday_days', 1)
        ->assertJsonPath('data.0.unjustified_absence_days', 2);

    expect($respuesta->json('data.0.unjustified_absence_days'))
        ->toBeLessThanOrEqual($respuesta->json('data.0.days_without_activity'));
})->group('RF-GP-04', 'RF-IN-01');

it('suma los dias-persona de un departamento entero sin desglosar por tipo', function (): void {
    // En los agregados los contadores son DIAS-PERSONA, igual que los demas
    // contadores de dias. Y no hay desglose por tipo a proposito (decision 7):
    // una fila por departamento con «dias de baja medica» es un agregado de dato
    // de salud que nadie ha pedido.
    $contexto = contextoDeInformeConAusencias();

    $otra = WorkforceFixtures::employee($contexto['site'], null, 'active', 'Lucia', 'Ferrer');

    PeriodReportFixtures::absence($contexto['employee'], 'sick_leave', '2026-03-11', '2026-03-12');
    PeriodReportFixtures::absence($otra, 'vacation', '2026-03-11', '2026-03-13');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-11', 'to' => '2026-03-13', 'granularity' => 'range', 'group_by' => 'site'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    // 2 + 3 dias-persona.
    $respuesta->assertJsonPath('data.0.absence_days', 5);

    // Y ni el tipo ni nada que lo parezca viaja en la respuesta: el detalle por
    // tipo vive en la pantalla de ausencias, con su alcance.
    expect(json_encode($respuesta->json(), JSON_THROW_ON_ERROR))->not->toContain('sick_leave');
})->group('RF-GP-04', 'RF-IN-02');

it('explica en los criterios que las ausencias justifican, cuantos festivos hay y que no conoce el cuadrante', function (): void {
    // Las tres lineas de la decision 7, en el idioma de la peticion. La del
    // cuadrante es la que impide que `unjustified_absence_days` se lea como un
    // recuento de faltas al trabajo: un numero asi no puede salir de la API sin
    // su advertencia al lado.
    $contexto = contextoDeInformeConAusencias();

    // Tres festivos en el calendario y **uno solo** dentro del periodo pedido.
    festivosDelPerfil(['2026-03-10', '2026-03-19', '2026-04-03']);

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidResponse(200);

    /** @var list<string> $criterios */
    $criterios = $respuesta->json('meta.criteria');

    $texto = implode(' ', $criterios);

    expect($texto)->toContain('no como absentismo')
        ->and($texto)->toContain('Hay 1 día(s) festivo(s)')
        // Con el nombre del perfil, que es lo que permite ir a mirarlo.
        ->and($texto)->toContain('ES-hosteleria')
        ->and($texto)->toContain('no conoce el cuadrante');

    // Ya traducidos: nunca una clave suelta.
    expect($texto)->not->toContain('criteria.');
})->group('RF-GP-04', 'RF-IN-01');

it('traduce las tres lineas nuevas al idioma de la peticion', function (): void {
    // Los textos van en `i18n` y el codigo en ingles (doc 02 §3.5). Sin esta
    // prueba, un cliente con el panel en ingles recibiria la advertencia del
    // cuadrante en castellano y nadie se enteraria hasta la demo.
    $contexto = contextoDeInformeConAusencias();

    festivosDelPerfil(['2026-03-10']);

    $respuesta = Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidResponse(200);

    /** @var list<string> $criterios */
    $criterios = $respuesta->json('meta.criteria');

    $texto = implode(' ', $criterios);

    expect($texto)->toContain('not as absenteeism')
        ->and($texto)->toContain('There are 1 public holiday(s)')
        ->and($texto)->toContain('does not know the shift roster');

    expect($texto)->not->toContain('criteria.');
})->group('RF-GP-04', 'RF-IN-01');

it('sigue contando cero festivos cuando el centro no ha cargado su calendario', function (): void {
    // El caso de serie: `holiday_calendar` esta vacio a proposito (los festivos
    // dependen del municipio y del año, regla dura 13). Un centro que no los haya
    // cargado tiene que ver la columna a cero, no la respuesta rota.
    $contexto = contextoDeInformeConAusencias();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-15', 'granularity' => 'range'])
        ->assertValidResponse(200);

    $respuesta->assertJsonPath('data.0.holiday_days', 0);

    // Y el criterio lo dice con su cero, en lugar de callarse: «cero festivos»
    // sobre un calendario vacio es informacion, no ausencia de informacion.
    expect(implode(' ', $respuesta->json('meta.criteria')))->toContain('Hay 0 día(s) festivo(s)');
})->group('RF-GP-04', 'RF-PD-07');
