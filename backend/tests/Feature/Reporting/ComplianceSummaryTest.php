<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **`GET /api/v1/compliance/summary`** — la vista de cumplimiento (RF-PA-06,
 * RS-05, tarea 3.4).
 *
 * Lo que se comprueba aqui es la **respuesta completa contra el contrato**: la
 * forma, los valores por omision, el criterio publicado, los recuentos, la
 * traduccion y el asiento de divulgacion. La aritmetica tiene sus unitarias
 * (`Tests\Unit\Reporting\Domain\Policy\ComplianceEvaluationTest`) y el alcance su
 * fichero (`ComplianceSummaryScopeTest`).
 *
 * **Las jornadas se siembran con el agregado y el proyector**, via
 * `PeriodReportFixtures::workDay()`: lo que esta vista afirma es que lee lo que
 * la proyeccion escribio, asi que fabricar los dos extremos no comprobaria nada.
 *
 * **El reloj se detiene con `FrozenTime`**, lo unico que para a la vez el puerto
 * `Clock` y el Carbon del framework: sin eso, el token de gestion se compara con
 * el reloj real y la prueba caeria con un `401` que no tiene nada que ver.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    config()->set('identity.two_factor.required_roles', []);

    // Martes 31 de marzo. Los 28 dias por omision terminan hoy: 4 → 31 de marzo.
    FrozenTime::at('2026-03-31 09:12:03');
});

/**
 * Un hotel con una persona que encadena dos jornadas con nueve horas de
 * descanso, y RRHH con su token.
 *
 * Nueve horas de descanso con el perfil `ES-hosteleria` (12 h) es un
 * incumplimiento de RN-10 de 180 minutos; la segunda jornada son 9 h 30 min, que
 * supera la ordinaria de 9 h.
 *
 * @return array{site: int, department: int, employee: string, token: string}
 */
function escenarioDeCumplimiento(): array
{
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');

    // Lunes 9: 09:00 → 17:00 (8 h).
    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    // Martes 10: entra a las 02:00, nueve horas despues de salir. Jornada de 9 h 30.
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    return [
        'site' => $site,
        'department' => $department,
        'employee' => $employee,
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
    ];
}

it('devuelve los hallazgos del periodo con la forma del contrato', function (): void {
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(2);

    // Orden: una sola persona, asi que manda la jornada y luego la regla.
    expect($respuesta->json('data.0.rule'))->toBe('insufficient_rest')
        ->and($respuesta->json('data.0.requirement'))->toBe('RN-10')
        ->and($respuesta->json('data.0.work_date'))->toBe('2026-03-10')
        ->and($respuesta->json('data.0.week'))->toBeNull()
        ->and($respuesta->json('data.0.measured_minutes'))->toBe(540)
        ->and($respuesta->json('data.0.threshold_minutes'))->toBe(720)
        // Los tres numeros van hechos para que la pantalla no reste.
        ->and($respuesta->json('data.0.difference_minutes'))->toBe(180)
        // RN-10 se cuelga del tramo que abre la jornada.
        ->and($respuesta->json('data.0.shift_entry_uuid'))->toBeString()
        ->and($respuesta->json('data.0.has_open_shift'))->toBeFalse()
        ->and($respuesta->json('data.0.employee.uuid'))->toBe($escenario['employee'])
        ->and($respuesta->json('data.0.employee.full_name'))->toBe('Youssef Amrani')
        // El fixture añade un sufijo aleatorio al nombre del departamento para no
        // chocar con el indice unico: se comprueba el prefijo.
        ->and($respuesta->json('data.0.employee.department.name'))->toStartWith('Cocina');

    expect($respuesta->json('data.1.rule'))->toBe('daily_excess')
        ->and($respuesta->json('data.1.requirement'))->toBe('RN-11')
        ->and($respuesta->json('data.1.measured_minutes'))->toBe(570)
        ->and($respuesta->json('data.1.threshold_minutes'))->toBe(540)
        ->and($respuesta->json('data.1.difference_minutes'))->toBe(30)
        // RN-11 no se cuelga de ningun tramo: ninguno por si solo explica el dia.
        ->and($respuesta->json('data.1.shift_entry_uuid'))->toBeNull();
})->group('RF-PA-06');

it('publica el criterio con el que ha medido: perfil, umbrales y semana', function (): void {
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertValidResponse(200);

    // Un aviso cuyo criterio no se ve es un aviso que nadie defiende ante un
    // empleado: el perfil se nombra y los cuatro umbrales viajan.
    expect($respuesta->json('meta.profile.name'))->toBe('ES-hosteleria')
        ->and($respuesta->json('meta.profile.jurisdiction'))->toBe('ES')
        ->and($respuesta->json('meta.time_zone'))->toBe('Europe/Madrid')
        ->and($respuesta->json('meta.week_starts_on'))->toBe(1)
        ->and($respuesta->json('meta.generated_at'))->toStartWith('2026-03-31T09:12:03')
        ->and($respuesta->json('meta.scope'))->toBe('all');

    /** @var list<array{requirement: string, threshold_minutes: int, evaluated: bool, suspension_reason: string|null}> $reglas */
    $reglas = $respuesta->json('meta.rules');

    expect($reglas)->toHaveCount(4)
        ->and(array_column($reglas, 'requirement'))->toBe(['RN-10', 'RN-11', 'RN-12', 'RN-17'])
        ->and(array_column($reglas, 'threshold_minutes'))->toBe([720, 540, 360, 2400])
        // RN-12 no se calla: se publica su umbral con `evaluated: false` y el
        // motivo, para que la pantalla lo explique (ADR-024, tarea 3.5).
        ->and(array_column($reglas, 'evaluated'))->toBe([true, true, false, true])
        ->and($reglas[2]['suspension_reason'])->toBe('awaiting_declared_break')
        ->and($reglas[0]['suspension_reason'])->toBeNull();
})->group('RF-PA-06', 'RF-PD-07');

it('cuenta los hallazgos por regla y las personas evaluadas', function (): void {
    $escenario = escenarioDeCumplimiento();

    // Una segunda persona que ficha y no incumple: entra en el denominador y no
    // en el numerador. Quien no ficho no ha cumplido ni incumplido.
    $otra = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'active', 'Lucia', 'Ferrer');
    PeriodReportFixtures::workDay($escenario['site'], $otra, '2026-03-09', '2026-03-09 09:00', '2026-03-09 15:00');

    // Y una tercera que no ficho en el periodo: no cuenta ni de lejos.
    WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'active', 'Marta', 'Ruiz');

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.totals.by_rule'))->toBe([
        'insufficient_rest' => 1,
        'daily_excess' => 1,
        'missing_break' => 0,
        'weekly_excess' => 0,
    ])
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(1)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(2);
})->group('RF-PA-06');

it('toma los 28 dias que terminan hoy cuando no le dan fechas', function (): void {
    // Cuatro semanas, la ventana que RRHH revisa, y **en la zona del centro**: a
    // las 00:30 de Madrid, resolverlo con la zona del servidor dejaria fuera la
    // jornada en curso justo en el turno de noche.
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.from'))->toBe('2026-03-04')
        ->and($respuesta->json('meta.to'))->toBe('2026-03-31')
        // Las dos jornadas del escenario caen dentro de la ventana.
        ->and($respuesta->json('data'))->toHaveCount(2);
})->group('RF-PA-06');

it('señala la semana entera aunque el rango la corte', function (): void {
    /*
     * Decision 6 de la ficha. Cinco jornadas de 8 h 12 min de lunes a viernes son
     * 41 h en la semana del 9 al 15; pedir solo el jueves tiene que devolver esa
     * semana completa, no las ocho horas de dentro del rango.
     */
    $site = WorkforceFixtures::site('Hotel de la semana', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Pisos');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Ana', 'Lopez');

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        PeriodReportFixtures::workDay($site, $employee, $date, $date.' 09:00', $date.' 17:12');
    }

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-12', 'to' => '2026-03-12']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.rule'))->toBe('weekly_excess')
        ->and($respuesta->json('data.0.requirement'))->toBe('RN-17')
        ->and($respuesta->json('data.0.work_date'))->toBeNull()
        ->and($respuesta->json('data.0.week.starts_on'))->toBe('2026-03-09')
        ->and($respuesta->json('data.0.week.ends_on'))->toBe('2026-03-15')
        ->and($respuesta->json('data.0.measured_minutes'))->toBe(2460)
        ->and($respuesta->json('data.0.difference_minutes'))->toBe(60)
        // RN-17 no abre incidencia: el computo del art. 34.1 ET es anual.
        ->and($respuesta->json('data.0.incident'))->toBeNull();
})->group('RN-17', 'RF-PA-06');

it('acota data con el filtro de regla y deja el criterio intacto', function (): void {
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary', [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'rule' => 'daily_excess',
    ]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.rule'))->toBe('daily_excess')
        // El filtro acota `data`, no el criterio: las cuatro reglas siguen ahi
        // con su umbral.
        ->and($respuesta->json('meta.rules'))->toHaveCount(4)
        // **Ni los recuentos.** Las cuatro tarjetas del panel son la foto
        // completa del periodo y no pueden cambiar al pulsar una de ellas: el
        // descanso corto de la otra jornada sigue contando aunque no se liste.
        ->and($respuesta->json('meta.totals.by_rule.insufficient_rest'))->toBe(1)
        ->and($respuesta->json('meta.totals.by_rule.daily_excess'))->toBe(1)
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(1);
})->group('RF-PA-06');

it('no deja nunca menos personas evaluadas que afectadas, ni con la semana del borde', function (): void {
    /*
     * El par imposible que el denominador tiene que evitar. Se pide **un solo
     * jueves** en el que nadie ficho, y la semana de ese jueves —lunes a
     * miercoles, tres jornadas de 14 h— se evalua completa (decision 6): sale un
     * `weekly_excess` de una persona cuyas jornadas caen todas fuera de
     * `[from, to]`.
     *
     * Contando el denominador solo sobre el rango pedido, la respuesta decia «1
     * afectada de 0 evaluadas», que es un ratio imposible y hace dudar de toda la
     * pantalla.
     */
    $site = WorkforceFixtures::site('Hotel del borde', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Sala');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Ana', 'Lopez');

    foreach (['2026-03-09', '2026-03-10', '2026-03-11'] as $date) {
        PeriodReportFixtures::workDay($site, $employee, $date, $date.' 06:00', $date.' 20:00');
    }

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-12', 'to' => '2026-03-12']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.rule'))->toBe('weekly_excess')
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(1)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(1);
})->group('RN-17', 'RF-PA-06');

it('acepta un from suelto y toma hoy en la zona del centro como fin', function (): void {
    // El camino que el techo del rango solo puede comprobar despues de resolverlo:
    // aqui el rango cabe y la respuesta sale, con `to` puesto al «hoy» del centro.
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary', ['from' => '2026-03-01']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.from'))->toBe('2026-03-01')
        ->and($respuesta->json('meta.to'))->toBe('2026-03-31')
        ->and($respuesta->json('data'))->toHaveCount(2);
})->group('RF-PA-06');

it('devuelve vacio y no 422 con un identificador de persona que no existe', function (): void {
    // `employee_uuid` se valida por FORMA y no contra la tabla, a proposito: un
    // `422` diria «ese identificador no existe» y un `200` vacio «existe y no es
    // tuyo», que convertiria este endpoint en un oraculo de existencia para un
    // responsable (RS-04).
    $escenario = escenarioDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary', [
        'employee_uuid' => '0199ffff-ffff-7fff-8fff-ffffffffffff',
    ]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(0)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(0);
})->group('RF-PA-06', 'RS-04');

it('sirve los criterios traducidos al idioma de la peticion', function (): void {
    // RF-KI-05: el producto se vende con español e ingles como minimo, y estos
    // textos van en la respuesta porque son parte del aviso.
    $escenario = escenarioDeCumplimiento();

    $es = Api::as($escenario['token'])->get('/api/v1/compliance/summary');
    $es->assertValidResponse(200);

    /** @var list<string> $criteriosEs */
    $criteriosEs = $es->json('meta.criteria');

    expect($criteriosEs)->toHaveCount(5)
        ->and($criteriosEs[0])->toContain('última salida')
        ->and(implode(' ', $criteriosEs))->toContain('informativa');

    $en = Api::as($escenario['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/compliance/summary');

    $en->assertValidResponse(200);

    /** @var list<string> $criteriosEn */
    $criteriosEn = $en->json('meta.criteria');

    expect($criteriosEn)->toHaveCount(5)
        ->and($criteriosEn[0])->toContain('last clock-out')
        // Y no son los mismos: una clave sin traducir devolveria el texto español
        // sin que nada fallara.
        ->and($criteriosEn)->not->toBe($criteriosEs);
})->group('RF-PA-06', 'RF-KI-05');

it('deja constancia del acceso con el alcance y sin ningun nombre', function (): void {
    // RS-05: lo que sale de aqui es una lista de personas con nombre y en que han
    // incumplido. El asiento describe el alcance y jamas lo divulgado (regla dura
    // 21).
    $escenario = escenarioDeCumplimiento();

    Api::as($escenario['token'])->get('/api/v1/compliance/summary', [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'department_id' => $escenario['department'],
    ])->assertValidResponse(200);

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed')->get();

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asientos->first()->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('compliance_summary')
        ->and($payload['record_count'] ?? null)->toBe(2)
        ->and($payload['from'] ?? null)->toBe('2026-03-01')
        ->and($payload['to'] ?? null)->toBe('2026-03-31')
        ->and($payload['department_id'] ?? null)->toBe($escenario['department'])
        // Se guarda QUE hubo filtro de persona, no cual: el detalle se responde
        // desde el registro de esa persona.
        ->and($payload['employee'] ?? null)->toBeFalse()
        ->and($payload['scope'] ?? null)->toBe('all')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Amrani')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain($escenario['employee']);
})->group('RF-PA-06', 'RS-05');

it('no agrupa los asientos: esta pantalla no se sondea', function (): void {
    // Al contrario que la presencia en vivo, que el panel pide cada 15 s. Aqui
    // cada apertura es un acceso real, y agruparla escondería el patron que una
    // inspeccion busca: alguien revisando el cumplimiento varias veces seguidas.
    $escenario = escenarioDeCumplimiento();

    Api::as($escenario['token'])->get('/api/v1/compliance/summary')->assertValidResponse(200);
    Api::as($escenario['token'])->get('/api/v1/compliance/summary')->assertValidResponse(200);

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(2);
})->group('RF-PA-06', 'RS-05');

it('responde 422 cuando el rango supera el presupuesto sincrono', function (): void {
    // 93 dias con el techo en 92. Se comprueba sobre el rango YA RESUELTO y antes
    // de tocar la base de datos.
    $escenario = escenarioDeCumplimiento();

    Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2025-12-29', 'to' => '2026-03-31'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-PA-06');

it('responde 422 con solo from cuando el hoy del centro deja el rango demasiado ancho', function (): void {
    // El caso que un techo comprobado solo sobre el par completo dejaria pasar:
    // un `from` suelto y ningun `to`. El `to` lo pone «hoy en la zona del
    // centro», que el borde no sabe resolver: del 1 de diciembre al 31 de marzo
    // van 121 dias, por encima de los 92.
    $escenario = escenarioDeCumplimiento();

    Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2025-12-01'])
        ->assertStatus(422);
})->group('RF-PA-06');

it('rechaza un parametro que no existe en lugar de ignorarlo', function (): void {
    // Un `?regla=` mal escrito devolveria las cuatro reglas en silencio y quien lo
    // envio se iria convencido de haber filtrado.
    $escenario = escenarioDeCumplimiento();

    Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['regla' => 'daily_excess'])
        ->assertStatus(422);
})->group('RF-PA-06');
