<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/reports/period` — el informe de horas por periodo, extremo a
 * extremo y contra el contrato (RF-IN-01, RF-IN-02, tarea 2.8).
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso.
 *
 * Lo que estas pruebas defienden, ademas de la forma de la respuesta:
 *
 *   - **Regla dura 4 / RN-05**: el turno 22:00 → 06:00 cuenta entero en la
 *     jornada en que empezo, tambien al agregarlo por semanas o por meses.
 *   - **Regla dura 7**: los minutos salen de `daily_totals`. Las jornadas se
 *     siembran por el agregado y su proyector, no escribiendo la proyeccion a
 *     mano: asi una prueba en verde significa que los dos extremos coinciden.
 *   - **`/informe-nuevo`**: los dias sin actividad aparecen con cero, y los
 *     criterios de inclusion salen **en la respuesta**.
 *   - **RN-14 / RF-GP-03**: quien causa baja a mitad de periodo conserva sus
 *     horas y sigue apareciendo.
 *   - **RS-05**: generar el informe deja constancia en `audit_log`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Centro en Madrid, un departamento, un empleado y una sesion de RRHH.
 *
 * @return array{token: string, site: int, department: int, employee: string}
 */
function contextoDeInforme(): array
{
    $site = WorkforceFixtures::site('Hotel de informes');
    $department = WorkforceFixtures::department($site, 'Cocina');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => $department,
        'employee' => WorkforceFixtures::employee($site, $department),
    ];
}

it('suma las horas del periodo con un conjunto de datos verificado a mano', function (): void {
    // Tres jornadas conocidas: 8 h + 8 h 30 + 6 h = 22 h 30 = 1350 minutos.
    // El resultado esta escrito como numero, no calculado por la prueba: si se
    // dedujera con la misma aritmetica que el codigo, las dos podrian estar mal
    // de la misma forma.
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');
    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-03', '2026-03-03 07:00', '2026-03-03 15:30');
    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-05', '2026-03-05 09:00', '2026-03-05 15:00');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', [
            'from' => '2026-03-01',
            'to' => '2026-03-07',
            'granularity' => 'range',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('from', '2026-03-01')
        ->assertJsonPath('to', '2026-03-07')
        ->assertJsonPath('meta.time_zone', 'Europe/Madrid')
        ->assertJsonPath('data.0.period.from', '2026-03-01')
        ->assertJsonPath('data.0.period.to', '2026-03-07')
        ->assertJsonPath('data.0.worked_minutes', 1350)
        // Y en `HH:MM`, que es lo que se lee. Nunca «22,5 horas».
        ->assertJsonPath('data.0.worked', '22:30')
        ->assertJsonPath('data.0.shift_count', 3)
        ->assertJsonPath('data.0.days_in_period', 7)
        ->assertJsonPath('data.0.days_with_activity', 3)
        ->assertJsonPath('data.0.days_without_activity', 4);
})->group('RF-IN-01');

it('atribuye un turno nocturno a la jornada en que empezo, tambien al agregar por meses', function (): void {
    // RN-05, ADR-006 y regla dura 4. El turno arranca el 31 de marzo a las 22:00
    // y termina el 1 de abril a las 06:00: son ocho horas de marzo, no cuatro y
    // cuatro, y no aparecen en abril.
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-31', '2026-03-31 22:00', '2026-04-01 06:00');

    $marzo = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.worked_minutes', 480)
        ->assertJsonPath('data.0.worked', '08:00')
        ->assertJsonPath('data.0.shift_count', 1);

    expect($marzo->json('data.0.period.to'))->toBe('2026-03-31');

    // Abril no ve ni un minuto de ese turno, aunque cuatro de sus horas
    // ocurrieran el dia 1.
    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-04-01', 'to' => '2026-04-30', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.worked_minutes', 0)
        ->assertJsonPath('data.0.shift_count', 0);
})->group('RN-05', 'RF-AT-08');

it('devuelve los dias sin actividad con cero en lugar de omitirlos', function (): void {
    // `/informe-nuevo`: para un informe de absentismo, omitir los dias sin
    // actividad es un error. Con granularidad diaria, una semana con un solo dia
    // trabajado tiene que devolver SIETE filas.
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-04', '2026-03-04 08:00', '2026-03-04 16:00');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-02', 'to' => '2026-03-08', 'granularity' => 'day'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.row_count', 7)
        ->assertJsonPath('data.0.period.from', '2026-03-02')
        ->assertJsonPath('data.0.worked_minutes', 0)
        ->assertJsonPath('data.2.period.from', '2026-03-04')
        ->assertJsonPath('data.2.worked_minutes', 480);

    /** @var array<int, int> $dias */
    $dias = $respuesta->json('data.*.days_in_period');

    // Cada fila diaria cuenta exactamente un dia, y la invariante del informe se
    // sostiene fila a fila.
    expect($dias)->toBe(array_fill(0, 7, 1));
})->group('RF-IN-01');

it('conserva las horas de quien causa baja a mitad de periodo', function (): void {
    // RN-14 y RF-GP-03: dar de baja no borra nada y el registro se conserva
    // cuatro anos (RL-02). Un informe de marzo que se olvidara de quien se fue
    // el dia 20 daria un total de departamento que no cuadra con ninguna nomina.
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-10', '2026-03-10 08:00', '2026-03-10 16:00');

    WorkforceFixtures::terminate($contexto['employee']);

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.row_count', 1)
        ->assertJsonPath('data.0.subject.employee_uuid', $contexto['employee'])
        ->assertJsonPath('data.0.worked_minutes', 480);
})->group('RN-14', 'RF-GP-03');

it('no cuenta los minutos de un dia con turno abierto, y lo dice', function (): void {
    // Un tramo sin cerrar vale cero en la proyeccion —todavia no ha terminado—,
    // asi que ese dia daria una cifra a medias justo en la comparacion contra lo
    // contratado. El dia SI cuenta como dia con actividad y sale en
    // `open_shift_days`: la ausencia de horas queda explicada, no escondida.
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-09', '2026-03-09 08:00', '2026-03-09 16:00');
    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-10', '2026-03-10 08:00', null);

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-09', 'to' => '2026-03-10', 'granularity' => 'range'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.worked_minutes', 480)
        ->assertJsonPath('data.0.open_shift_days', 1)
        ->assertJsonPath('data.0.days_with_activity', 2);
})->group('RF-IN-01');

it('agrega por departamento y por centro sin desglosar a nadie', function (): void {
    // RF-IN-02. Los dos agregados suman lo mismo porque en esta prueba solo hay
    // un departamento, y esa igualdad es justo lo que hay que poder comprobar:
    // si el total del centro no cuadrara con la suma de sus departamentos,
    // faltaria gente en alguno de los dos.
    $contexto = contextoDeInforme();
    $segundo = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-02', '2026-03-02 08:00', '2026-03-02 16:00');
    PeriodReportFixtures::workDay($contexto['site'], $segundo, '2026-03-02', '2026-03-02 08:00', '2026-03-02 12:00');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', [
            'from' => '2026-03-02',
            'to' => '2026-03-02',
            'granularity' => 'day',
            'group_by' => 'department',
        ])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.row_count', 1)
        ->assertJsonPath('data.0.subject.kind', 'department')
        ->assertJsonPath('data.0.subject.employee_uuid', null)
        ->assertJsonPath('data.0.worked_minutes', 720)
        // Dias-persona: dos personas por un dia natural.
        ->assertJsonPath('data.0.days_in_period', 2);

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', [
            'from' => '2026-03-02',
            'to' => '2026-03-02',
            'granularity' => 'day',
            'group_by' => 'site',
        ])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.subject.kind', 'site')
        ->assertJsonPath('data.0.worked_minutes', 720);
})->group('RF-IN-01', 'RF-IN-02');

it('recorta al rango pedido las semanas que sobresalen', function (): void {
    // Una fila describe exactamente los dias que ha contado. Si dijera «semana
    // del 23 de febrero» con el total de un solo dia, quien la lee compararia
    // siete dias con uno. El 1 de marzo de 2026 es domingo, asi que su semana
    // ISO empieza el lunes 23 de febrero.
    $contexto = contextoDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-08', 'granularity' => 'week'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.period.from', '2026-03-01')
        ->assertJsonPath('data.0.period.to', '2026-03-01')
        ->assertJsonPath('data.0.days_in_period', 1)
        ->assertJsonPath('data.1.period.from', '2026-03-02')
        ->assertJsonPath('data.1.period.to', '2026-03-08')
        ->assertJsonPath('data.1.days_in_period', 7);
})->group('RF-IN-01');

it('devuelve los criterios de inclusion en la propia respuesta', function (): void {
    // `/informe-nuevo`, paso 1: los criterios van visibles en el informe. Sin
    // ellos, cada persona interpreta la tabla a su manera y la interpretacion
    // acaba discutiendose en una reunion de nomina.
    $contexto = contextoDeInforme();

    App::setLocale('es');

    $excluidos = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'week'])
        ->assertValidResponse(200);

    /** @var list<string> $criterios */
    $criterios = $excluidos->json('meta.criteria');

    expect($criterios)->toBeArray()
        ->and($criterios)->not->toBeEmpty()
        // Ya traducidos: nunca una clave suelta como `criteria.source`.
        ->and(implode(' ', $criterios))->not->toContain('criteria.')
        ->and(implode(' ', $criterios))->toContain('no se parte a medianoche')
        ->and(implode(' ', $criterios))->toContain('no aportan minutos')
        // La semana ISO solo se menciona cuando se ha pedido por semanas.
        ->and(implode(' ', $criterios))->toContain('ISO 8601');

    $incluidos = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', [
            'from' => '2026-03-01',
            'to' => '2026-03-07',
            'granularity' => 'range',
            'include_open_shifts' => '1',
        ])
        ->assertValidResponse(200);

    /** @var list<string> $conAbiertos */
    $conAbiertos = $incluidos->json('meta.criteria');

    // El criterio de los turnos abiertos CAMBIA con el parametro: un informe que
    // dijera siempre lo mismo mentiria en uno de los dos casos.
    expect(implode(' ', $conAbiertos))->toContain('aportan los minutos que ya tienen cerrados')
        ->and(implode(' ', $conAbiertos))->not->toContain('ISO 8601');
})->group('RF-IN-01', 'RF-IN-02');

it('traduce los criterios al idioma de la peticion', function (): void {
    // Los textos van en `i18n` y el codigo en ingles (doc 02 §3.5). Sin esta
    // prueba, un cliente con el panel en ingles recibiria los criterios en
    // castellano y nadie se enteraria hasta la demo.
    $contexto = contextoDeInforme();

    App::setLocale('en');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'week'])
        ->assertValidResponse(200);

    /** @var list<string> $criterios */
    $criterios = $respuesta->json('meta.criteria');

    expect(implode(' ', $criterios))->toContain('is not split at midnight')
        ->and(implode(' ', $criterios))->toContain('ISO 8601')
        ->and(implode(' ', $criterios))->not->toContain('criteria.');
})->group('RF-IN-01', 'RQ-11');

it('rechaza un rango de mas de tres meses remitiendo a la generacion en diferido', function (): void {
    // RNF-P-05 y `/informe-nuevo` paso 5. La respuesta honesta a una peticion que
    // se sale del presupuesto no es intentarlo: un informe de cuarenta segundos
    // ocupa la misma base de datos que atiende el fichaje (RNF-P-02).
    $contexto = contextoDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-01-01', 'to' => '2026-06-30', 'granularity' => 'month'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.to.0', fn (mixed $mensaje): bool => \is_string($mensaje)
            && str_contains($mensaje, 'diferido'));
})->group('RF-IN-01', 'RNF-P-05');

it('deja constancia en audit_log de que se ha generado el informe', function (): void {
    // RS-05: salen horas de trabajo de personas identificadas, que es el dato
    // personal mas sensible que este producto guarda de nadie. Se registra el
    // ALCANCE y nunca lo divulgado (regla dura 21).
    $contexto = contextoDeInforme();

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-02', '2026-03-02 08:00', '2026-03-02 16:00');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asiento->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('period_report')
        ->and($payload['from'] ?? null)->toBe('2026-03-01')
        ->and($payload['to'] ?? null)->toBe('2026-03-31')
        ->and($payload['granularity'] ?? null)->toBe('month')
        ->and($payload['employees'] ?? null)->toBe(1)
        // El conjunto es pequeño y sale de la instalacion: los afectados se
        // enumeran por su UUID publico, nunca por su nombre (RL-15).
        ->and($payload['employee_uuids'] ?? null)->toBe($contexto['employee'])
        // Y nada de lo divulgado: ni una hora, ni un total, ni un nombre.
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('480');
})->group('RF-IN-01', 'RS-05');
